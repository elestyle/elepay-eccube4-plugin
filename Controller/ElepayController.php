<?php

namespace Plugin\elepay42\Controller;

require_once(__DIR__ . '/../Resource/vendor/autoload.php');

use DateTime;
use Eccube\Service\PurchaseFlow\PurchaseContext;
use Eccube\Exception\ShoppingException;
use Eccube\Service\PurchaseFlow\PurchaseException;
use Eccube\Service\PurchaseFlow\PurchaseFlow;
use Elepay\ApiException;
use Exception;
use Eccube\Service\OrderHelper;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Eccube\Controller\AbstractController;
use Eccube\Entity\Order;
use Eccube\Entity\Master\OrderStatus;
use InvalidArgumentException;
use Eccube\Common\Constant;
use Plugin\elepay42\Service\ElepayHelper;
use Plugin\elepay42\Service\LoggerService;

/**
 * Routes are declared twice: as docblock annotations (read by EC-CUBE 4.2/4.3) and as PHP attributes (read by 4.4, and by 4.2/4.3 on PHP 8).
 * 4.2/4.3 on PHP 8 read both, and the one read later overrides the other, so always keep the two identical.
 * Always write each attribute on a single line, so that PHP 7.4 skips it as a line comment.
 */
class ElepayController extends AbstractController
{
    /**
     * Order ID held by the browser that started the payment. Used so that only the browser that paid for the order completes it on the return from elepay
     */
    private const SESSION_CHECKOUT_ORDER_ID = 'elepay42.checkout.order_id';

    /**
     * @var ElepayHelper
     */
    protected $elepayHelper;

    /**
     * @var LoggerService
     */
    protected $logger;

    /**
     * @var PurchaseFlow
     */
    protected $purchaseFlow;

    /**
     * @var RequestStack
     */
    protected $requestStack;

    /**
     * @var string
     */
    private $pluginVersion;

    public function __construct(
        ElepayHelper $elepayHelper,
        LoggerService $loggerService,
        PurchaseFlow $shoppingPurchaseFlow,
        RequestStack $requestStack
    ) {
        $this->elepayHelper = $elepayHelper;
        $this->logger = $loggerService;
        $this->purchaseFlow = $shoppingPurchaseFlow;
        $this->requestStack = $requestStack;
        $this->pluginVersion = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true)['version'] ?? '';
    }

    /**
     * Shows the elepay payment page.
     *
     * @Route("/elepay_checkout", name="elepay_checkout")
     *
     * @param Request $request
     *
     * @return array|RedirectResponse
     * @throws Exception
     */
    #[Route('/elepay_checkout', name: 'elepay_checkout')]
    public function checkout(Request $request)
    {
        /** @var Order $order */
        $order = $this->elepayHelper->getCartOrder();
        if (empty($order)) {
            $this->logger->error('[注文確認] 購入処理中の受注が存在しません.');
            return $this->redirectToRoute('shopping_error');
        }

        // On direct access, do not start a payment for an order that has not gone through PaymentMethod::apply() (stock reservation etc.)
        if ($order->getOrderStatus() === null || $order->getOrderStatus()->getId() !== OrderStatus::PENDING) {
            $this->logger->error('[注文確認] 決済処理中の受注ではありません.', [$order->getOrderNo()]);
            return $this->redirectToRoute('shopping_error');
        }

        if ($order->getPaymentTotal() == 0) {
            // No payment is needed, so mark the order as PAID without going through elepay
            $this->settleOrder($order);
            $this->orderComplete($order);

            return $this->redirectToRoute('shopping_complete');
        }

        $this->requestStack->getSession()->set(self::SESSION_CHECKOUT_ORDER_ID, $order->getId());

        // Generate absolute URLs with the router. This also resolves the base path when the shop is installed in a subdirectory
        $checkoutValidateUrl = $this->generateUrl(
            'elepay_checkout_validate',
            [
                'orderNo' => $this->elepayHelper->getOrderNo($order)
            ],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        $codeMetadata = [
            'client' => 'eccube',
            'clientVersion' => Constant::VERSION,
            'pluginVersion' => $this->pluginVersion
        ];

        try {
            $paymentObject = $this->elepayHelper->createCodeObject($order, $checkoutValidateUrl, $codeMetadata);
            $codeUrl = $paymentObject['codeUrl'] ?? null;
            if (!$this->elepayHelper->isHttpsUrl($codeUrl)) {
                $this->logger->error('[注文処理] elepay の決済画面の URL が https ではありません.', [$order->getOrderNo()]);
                return $this->redirectToRoute('shopping_error');
            }
            $redirectUrl = $this->elepayHelper->addQuery(
                $codeUrl,
                [
                    'mode' => 'auto',
                    'locale' => $request->getLocale()
                ]
            );
            return $this->redirect($redirectUrl);
        } catch (ApiException $e) {
            $this->logger->error('[注文処理] Exception when calling CodeApi->createCode::' . $e->getMessage(), [$order->getOrderNo()]);
            return $this->redirectToRoute('shopping_error');
        } catch (InvalidArgumentException $e) {
            $this->logger->error('[注文処理] Exception when calling CodeApi->createCode::' . $e->getMessage(), [$order->getOrderNo()]);
            return $this->redirectToRoute('shopping_error');
        }
    }

    /**
     * Checkout Validate
     *
     * @Route("/elepay_checkout_validate", name="elepay_checkout_validate")
     *
     * @param Request $request
     *
     * @return RedirectResponse
     * @throws Exception
     */
    #[Route('/elepay_checkout_validate', name: 'elepay_checkout_validate')]
    public function checkoutValidate(Request $request): RedirectResponse
    {
        $status = $request->query->get('status');
        $codeId = $request->query->get('codeId');
        $chargeId = $request->query->get('chargeId');
        $orderNo = $this->elepayHelper->parseOrderNo($request->query->get('orderNo'));

        /** @var Order $order */
        $order = $this->elepayHelper->getOrderByNo($orderNo);

        if (empty($order)) {
            $this->logger->error('[注文確認] 購入処理中の受注が存在しません.');
            return $this->redirectToRoute('shopping_error');
        }

        // Anyone can put an order number in the URL, so only the browser that started this order's payment
        // gets the completion page or has the order rolled back.
        // The payment itself is verified through the elepay API, so confirming the payment runs regardless of the browser
        $isOwner = $this->requestStack->getSession()->get(self::SESSION_CHECKOUT_ORDER_ID) === $order->getId();

        if ($this->isOrderSettled($order)) {
            if ($isOwner) {
                return $this->redirectToComplete($order);
            }
            // Tell a browser other than the one that started the payment only once this return's payment is confirmed to belong to this order
            $chargeObject = $status === 'captured' ? $this->fetchReturnedCharge($order, $chargeId, $codeId) : null;
            if ($chargeObject !== null && $this->chargeMatchesOrder($order, $chargeObject)) {
                return $this->redirectToPaidNotice($order);
            }
            return $this->redirectToRoute('homepage');
        }

        if ($status === 'captured') {
            $chargeObject = $this->fetchReturnedCharge($order, $chargeId, $codeId);
            if ($chargeObject === null) {
                return $this->redirectToRoute('shopping_error');
            }

            $result = $this->orderValidate($order, $chargeObject);

            if ($result === 'error') {
                return $this->redirectToRoute('shopping_error');
            }
            return $isOwner ? $this->redirectToComplete($order) : $this->redirectToPaidNotice($order);
        } else {
            if (!$isOwner) {
                $this->logger->error('[注文確認] 決済を開始したブラウザではないため受注をロールバックしません.', [$order->getOrderNo()]);
                return $this->redirectToRoute('shopping_error');
            }

            // Roll back to PROCESSING only while the order is PENDING, so that a PAID order is not rolled back
            // when a cancel races with a payment made on another device
            if ($this->elepayHelper->transitionOrderStatus($order, [OrderStatus::PENDING], OrderStatus::PROCESSING)) {
                $order->setOrderStatus($this->elepayHelper->getOrderStatusProcessing());

                // Call purchaseFlow::rollback to roll back the purchase.
                // Restoring stock and points only changes the entities, so flush afterwards to save them
                $this->purchaseFlow->rollback($order, new PurchaseContext());
                $this->entityManager->flush();
            } else {
                $latestStatusId = $this->elepayHelper->fetchLatestOrderStatusId($order);
                if ($latestStatusId !== null && !in_array($latestStatusId, [OrderStatus::PENDING, OrderStatus::PROCESSING], true)) {
                    $this->logger->info('[注文確認] キャンセル時に受注は既に確定済みでした.', [$order->getOrderNo()]);
                    return $this->redirectToComplete($order);
                }
            }

            if ($status === 'cancelled') {
                $this->logger->error('[注文確認] Order cancelled.', [$order->getOrderNo()]);
                return $this->redirectToRoute('shopping');
            } else {
                $this->logger->error('[注文確認] Unknown error.', [$order->getOrderNo()]);
                return $this->redirectToRoute('shopping_error');
            }
        }
    }

    /**
     * Handles the webhook
     *
     * @Route("/elepay_paid_webhook", name="elepay_paid_webhook", methods={"POST"})
     *
     * @return Response
     * @throws ApiException
     */
    #[Route('/elepay_paid_webhook', name: 'elepay_paid_webhook', methods: ['POST'])]
    public function elepayWebhook(Request $request)
    {
        $this->logger->info('*****  Elepay Webhook start.  ***** ');

        // Anyone can send the body, so trust nothing in it but the charge ID. Decide on the order from the charge fetched again from the elepay API,
        // and answer every request whose charge cannot be verified with the same error whatever the order's status (so it cannot reveal whether the order is paid)
        $data = json_decode($request->getContent(), true);
        $object = is_array($data) ? ($data['data']['object'] ?? null) : null;
        $orderNo = is_array($object) && is_string($object['orderNo'] ?? null) ? $this->elepayHelper->parseOrderNo($object['orderNo']) : '';
        $chargeId = is_array($object) && is_string($object['id'] ?? null) ? $object['id'] : '';

        $this->logger->info('[注文確認] Order No :', [$orderNo]);

        /** @var Order|null $order */
        $order = ($orderNo !== '' && $chargeId !== '') ? $this->elepayHelper->getOrderByNo($orderNo) : null;
        $chargeObject = null;

        if (empty($order)) {
            $this->logger->error('[注文確認] 購入処理中の受注が存在しません.');
            $result = 'error';
        } else {
            try {
                $chargeObject = $this->elepayHelper->getChargeObject($chargeId);
            } catch (ApiException | InvalidArgumentException $e) {
                $this->logger->error('[注文確認] Exception when calling ChargeApi->retrieveCharge::' . $e->getMessage(), [$order->getOrderNo()]);
            }
            $result = is_array($chargeObject) ? $this->orderValidate($order, $chargeObject) : 'error';
        }

        if ($result === 'already_paid') {
            // For an order already settled by the customer's return or an earlier webhook delivery, return success without repeating the completion, so elepay stops retrying.
            // The request that settled the order has already deleted the cart, so leave it alone
            $this->logger->info('[注文確認] 受注は既に確定済みです.', [$order->getOrderNo()]);
            $result = 'success';
        } else if ($result === 'success') {
            // A webhook has no customer session, so delete the cart with the same pre_order_id as the order
            $this->elepayHelper->removeCartByPreOrderId($order->getPreOrderId());
            $this->logger->info('[注文完了] 注文完了.');
        }

        $this->logger->info('*****  Elepay Webhook end.  *****');
        return new Response($result, $result === 'success' ? 200 : 400);
    }

    /**
     * Redirects from the admin order list to the charge details on the elepay dashboard.
     *
     * It calls the elepay API with the shop's secret key, so it sits inside the admin firewall (admin authentication, IP restriction,
     * two-factor authentication). Outside the admin URL it would be reachable anonymously
     *
     * @Route("/%eccube_admin_route%/elepay/charge_redirect", name="elepay_refund_validate")
     *
     * @param Request $request
     *
     * @return RedirectResponse
     */
    #[Route('/%eccube_admin_route%/elepay/charge_redirect', name: 'elepay_refund_validate')]
    public function adminRedirect(Request $request): RedirectResponse
    {
        $chargeId = $request->query->get('chargeId');
        $chargeObject = null;
        if (is_string($chargeId) && $chargeId !== '') {
            try {
                $chargeObject = $this->elepayHelper->getChargeObject($chargeId);
            } catch (ApiException | InvalidArgumentException $e) {
                $this->logger->error('[管理画面] Exception when calling ChargeApi->retrieveCharge::' . $e->getMessage(), [$chargeId]);
            }
        }
        if (!is_array($chargeObject) || !is_string($chargeObject['appId'] ?? null) || $chargeObject['appId'] === '') {
            throw new NotFoundHttpException();
        }

        $redirectUrl = $this->eccubeConfig->get('elepay.admin_host') .
            '/apps/' . rawurlencode($chargeObject['appId']) . '/gw/payment/charges/' . rawurlencode($chargeId);
        return $this->redirect($redirectUrl);
    }

    /**
     * Tells a customer who returned from elepay to a browser other than the one that started the payment only that the payment is complete
     *
     * @Route("/elepay_paid", name="elepay_paid")
     *
     * @return Response
     */
    #[Route('/elepay_paid', name: 'elepay_paid')]
    public function paid(): Response
    {
        return $this->render('@elepay42/default/Shopping/paid.twig');
    }

    /**
     * Fetches the charge using the parameters of the URL returned from elepay. null if it cannot be fetched
     *
     * @param Order $order
     * @param string|null $chargeId
     * @param string|null $codeId
     * @return array|null
     */
    private function fetchReturnedCharge(Order $order, $chargeId, $codeId): ?array
    {
        try {
            if (!empty($chargeId)) {
                $chargeObject = $this->elepayHelper->getChargeObject($chargeId);
            } else if (!empty($codeId)) {
                $codeObject = $this->elepayHelper->getCodeObject($codeId);
                $chargeObject = $codeObject['charge'] ?? null;
            }
        } catch (ApiException $e) {
            $this->logger->error('[注文確認] Exception when calling CodeApi->retrieveCode::' . $e->getMessage(), [$order->getOrderNo()]);
            return null;
        } catch (InvalidArgumentException $e) {
            $this->logger->error('[注文処理] Exception when calling CodeApi->retrieveCode::' . $e->getMessage(), [$order->getOrderNo()]);
            return null;
        }

        if (empty($chargeObject)) {
            $this->logger->error('[注文処理] ChargeObject is empty', [$order->getOrderNo()]);
            return null;
        }
        return $chargeObject;
    }

    /**
     * @param Order $order
     * @param array $chargeObject
     * @return bool true if the payment is settled and belongs to this order
     */
    private function chargeMatchesOrder(Order $order, array $chargeObject): bool
    {
        return ($chargeObject['status'] ?? null) === 'captured'
            && $this->elepayHelper->parseOrderNo($chargeObject['orderNo'] ?? '') == $order->getOrderNo();
    }

    /**
     * Sends the browser that started the payment to the purchase complete page
     *
     * @param Order $order
     * @return RedirectResponse
     */
    private function redirectToComplete(Order $order): RedirectResponse
    {
        $this->orderComplete($order);
        return $this->redirectToRoute('shopping_complete');
    }

    /**
     * Tells a browser other than the one that started the payment (e.g. when the payment app returns to a different browser) only that the payment is complete.
     * The caller must have verified through the elepay API that this order's payment is settled
     *
     * @param Order $order
     * @return RedirectResponse
     */
    private function redirectToPaidNotice(Order $order): RedirectResponse
    {
        $this->logger->info('[注文確認] 決済を開始したブラウザではないため購入完了画面を表示しません.', [$order->getOrderNo()]);
        // Delete the cart of the browser that started the payment so that it is not purchased again
        $this->elepayHelper->removeCartByPreOrderId($order->getPreOrderId());
        return $this->redirectToRoute('elepay_paid');
    }

    /**
     * For a request from the customer's browser, does the cleanup before moving to the purchase complete page
     *
     * @param Order $order
     */
    private function orderComplete(Order $order)
    {
        $this->logger->info('[注文処理] カートをクリアします.', [$order->getOrderNo()]);
        $this->elepayHelper->cartClear();

        $session = $this->requestStack->getSession();
        // Clear the in-payment marker so that reloading the return URL after completion does not delete the cart as it is then
        $session->remove(self::SESSION_CHECKOUT_ORDER_ID);
        // The purchase complete page looks up SESSION_ORDER_ID as the order ID, so store the ID rather than the order number
        $session->set(OrderHelper::SESSION_ORDER_ID, $order->getId());
    }

    /**
     * Whether the order has been settled by a payment. Besides PAID, this includes orders later moved on in the admin, e.g. to shipped or cancelled
     *
     * @param Order $order
     * @return bool
     */
    private function isOrderSettled(Order $order): bool
    {
        $status = $order->getOrderStatus();
        return $status !== null && !in_array($status->getId(), [OrderStatus::PENDING, OrderStatus::PROCESSING], true);
    }

    /**
     * Matches the order against the elepay charge and, if they match, marks the order as PAID
     *
     * @param Order $order
     * @param array $chargeObject
     * @return string success: marked as PAID by this call / already_paid: already settled / error: the match failed
     */
    private function orderValidate(Order $order, array $chargeObject): string
    {
        // Make sure the charge belongs to this order before telling whether the order is already settled
        $orderNo = $order->getOrderNo();
        $chargeOrderNo = $this->elepayHelper->parseOrderNo((string)($chargeObject['orderNo'] ?? ''));
        if ($orderNo != $chargeOrderNo) {
            $this->logger->error('[注文確認] ERROR::Verify payment order error.' . PHP_EOL . '  ec[order_no] : ' . $orderNo . ' / elepay[order_no] : ' . $chargeOrderNo);
            return 'error';
        }

        if ($this->isOrderSettled($order)) {
            $this->warnIfDuplicateCharge($order, $chargeObject);
            return 'already_paid';
        }

        $orderAmount = $order->getPaymentTotal();
        $chargeAmount = $chargeObject['amount'];

        if ($orderAmount != $chargeAmount) {
            $this->logger->error('[注文確認] Verify payment amount error.' . PHP_EOL . '  ec[payment_total] : ' . $orderAmount . ' / elepay[amount] : ' . $chargeAmount, [$order->getOrderNo()]);
            return 'error';
        }

        $chargeStatus = $chargeObject['status'];

        if ($chargeStatus !== 'captured') {
            $this->logger->error('[注文確認] Verify payment status error : status is ' . $chargeStatus, [$order->getOrderNo()]);
            return 'error';
        }

        $paymentMethodName = $chargeObject['paymentMethod'];
        if ($paymentMethodName === 'creditcard') {
            $paymentMethodName = 'creditcard_' . $chargeObject['cardInfo']['brand'];
        }

        $paymentMethods = $this->elepayHelper->getPaymentMethods();
        foreach ($paymentMethods as $paymentMethod) {
            if ($paymentMethod['key'] === $paymentMethodName) {
                $paymentMethodName = $paymentMethod['name'];
                break;
            }
        }

        // When the webhook and the customer's return arrive together, both read the unsettled order,
        // so let the DB narrow the request that runs the settlement (committing the purchase flow, the order mail) down to one
        if ($this->elepayHelper->transitionOrderStatus($order, [OrderStatus::PENDING], OrderStatus::PAID)) {
            $revived = false;
        } else if ($this->elepayHelper->transitionOrderStatus($order, [OrderStatus::PROCESSING], OrderStatus::PAID)) {
            // A cancel during payment was processed first, and the payment was captured after stock and points had been restored. Accept the payment
            $revived = true;
        } else {
            return 'already_paid';
        }

        $this->settleOrder($order, $paymentMethodName, $chargeObject['id'], $revived);

        return 'success';
    }

    /**
     * Payment codes are created from the order number plus a timestamp, so one order can end up with several captured payments.
     * When a settled order receives a payment other than the one that settled it, log it so that the shop can decide whether to refund
     *
     * @param Order $order
     * @param array $chargeObject
     */
    private function warnIfDuplicateCharge(Order $order, array $chargeObject): void
    {
        $chargeId = $chargeObject['id'] ?? null;
        $settledChargeId = $order->getElepayChargeId();
        // Settled orders without a charge ID (orders needing no payment, orders settled by older versions) cannot be compared, so skip them
        if (($chargeObject['status'] ?? null) !== 'captured' || empty($chargeId) || empty($settledChargeId) || $chargeId === $settledChargeId) {
            return;
        }
        $this->logger->error('[注文確認] 確定済みの受注に別の決済が届きました. 二重の支払いのため返金の要否を確認してください.', [
            $order->getOrderNo(),
            'settled_charge_id' => $settledChargeId,
            'charge_id' => $chargeId,
        ]);
    }

    /**
     * Settles the order as PAID and sends the order mail
     *
     * @param Order $order
     * @param string|null $paymentMethodName Payment method name on elepay. null when no payment is involved
     * @param string|null $chargeId
     * @param bool $revived true when settling an order that a cancel rolled back to PROCESSING
     */
    private function settleOrder(Order $order, ?string $paymentMethodName = null, ?string $chargeId = null, bool $revived = false): void
    {
        $connection = $this->entityManager->getConnection();

        try {
            if ($revived) {
                $this->reserveAgain($order);
            }
            $this->markOrderPaid($order, $paymentMethodName, $chargeId);
        } catch (\Throwable $e) {
            // EC-CUBE rolls back the request's transaction only when it is marked rollback-only.
            // Make sure the transition to PAID is not committed on its own, leaving an order that missed the settlement steps
            if ($connection->isTransactionActive()) {
                $connection->setRollbackOnly();
            }
            throw $e;
        }

        // Only log the exception, so that an SMTP failure does not undo the settlement of the payment.
        // But when the transaction has become rollback-only (e.g. saving the mail history failed), the settlement is not committed either,
        // so rethrow the exception instead of responding with success
        $this->logger->info('[注文処理] 注文メールの送信を行います.', [$order->getOrderNo()]);
        try {
            $this->elepayHelper->sendOrderMail($order);
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive() && $connection->isRollbackOnly()) {
                throw $e;
            }
            $this->logger->error('[注文処理] 注文メールの送信に失敗しました: ' . $e->getMessage(), [$order->getOrderNo()]);
        }
    }

    /**
     * Reserves again, through the purchase flow's prepare, the stock, points etc. that a cancel restored.
     * The payment is accepted even if they cannot be reserved (out of stock etc.), so log a warning for the shop to check the order
     *
     * @param Order $order
     */
    private function reserveAgain(Order $order): void
    {
        $this->logger->warning('[注文処理] キャンセル処理後に決済が確定しました. 在庫・ポイントを確保し直します.', [$order->getOrderNo()]);
        try {
            $this->purchaseFlow->prepare($order, new PurchaseContext());
        } catch (PurchaseException | ShoppingException $e) {
            $this->logger->error('[注文処理] 在庫・ポイントを確保し直せませんでした. 受注内容を確認してください: ' . $e->getMessage(), [$order->getOrderNo()]);
        }
    }

    /**
     * Commits the purchase flow and marks the order as PAID
     *
     * @param Order $order
     * @param string|null $paymentMethodName Payment method name on elepay. null when no payment is involved
     * @param string|null $chargeId
     */
    private function markOrderPaid(Order $order, ?string $paymentMethodName = null, ?string $chargeId = null): void
    {
        // Call purchaseFlow::commit to complete the purchase.
        // The purpose of this operation is to set the order_date of the order,
        // but it also changes the order status to new,
        // so it must be executed before the order status changes
        $this->purchaseFlow->commit($order, new PurchaseContext());

        $order->setOrderStatus($this->elepayHelper->getOrderStatusPaid());
        $order->setPaymentDate(new DateTime());
        if ($paymentMethodName !== null) {
            $order->setPaymentMethod($paymentMethodName);
        }
        if ($chargeId !== null) {
            $order->setElepayChargeId($chargeId); // If paying using a widget, need to save the chargeId here
        }
        $this->entityManager->flush();
    }
}
