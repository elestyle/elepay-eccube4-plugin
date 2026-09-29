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
use Eccube\Controller\AbstractController;
use Eccube\Entity\Order;
use Eccube\Entity\Master\OrderStatus;
use InvalidArgumentException;
use Eccube\Common\Constant;
use Plugin\elepay42\Service\ElepayHelper;
use Plugin\elepay42\Service\LoggerService;

/**
 * ルートは docblock アノテーション（EC-CUBE 4.2/4.3 が読む）と PHP Attribute（4.4 と PHP 8 上の 4.2/4.3 が読む）の二重定義。
 * PHP 8 上の 4.2/4.3 は両方を読み、後から読んだ方で上書きするため、両者の内容は常に一致させること。
 * PHP 7.4 で Attribute を行コメントとして読み飛ばさせるため、Attribute は必ず 1 行で書くこと。
 */
class ElepayController extends AbstractController
{
    /**
     * 決済を開始したブラウザの受注 ID. elepay からの戻りを、その受注を決済したブラウザだけが完了させるために使う
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
     * elepay 決済画面を表示する.
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

        // 直接アクセスされた場合に、PaymentMethod::apply() の在庫確保等を経ていない受注で決済を始めない
        if ($order->getOrderStatus() === null || $order->getOrderStatus()->getId() !== OrderStatus::PENDING) {
            $this->logger->error('[注文確認] 決済処理中の受注ではありません.', [$order->getOrderNo()]);
            return $this->redirectToRoute('shopping_error');
        }

        if ($order->getPaymentTotal() == 0) {
            // 決済不要のため elepay を経由せずに入金済みにする
            $this->settleOrder($order);
            $this->orderComplete($order);

            return $this->redirectToRoute('shopping_complete');
        }

        $this->requestStack->getSession()->set(self::SESSION_CHECKOUT_ORDER_ID, $order->getId());

        // ルーターで絶対 URL を生成する. サブディレクトリ設置時のベースパスもここで解決される
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
            $redirectUrl = $this->elepayHelper->addQuery(
                $paymentObject['codeUrl'],
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

        // URL の受注番号は誰でも指定できるため、この受注の決済を開始したブラウザ以外には
        // 完了画面の表示・受注のロールバックを行わない.
        // 決済自体は elepay の API で検証するため、決済確定の処理だけはブラウザに関係なく行う
        $isOwner = $this->requestStack->getSession()->get(self::SESSION_CHECKOUT_ORDER_ID) === $order->getId();

        if ($this->isOrderSettled($order)) {
            if ($isOwner) {
                return $this->redirectToComplete($order);
            }
            // 決済を開始したブラウザ以外には、この戻りの決済が本当にこの受注のものであると確認できた場合だけ知らせる
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

            // 別端末での支払いと同時にキャンセルされた場合に、入金済みの受注を購入処理中へ戻さないよう、
            // 決済処理中のときだけ購入処理中へ戻す
            if ($this->elepayHelper->transitionOrderStatus($order, [OrderStatus::PENDING], OrderStatus::PROCESSING)) {
                $order->setOrderStatus($this->elepayHelper->getOrderStatusProcessing());

                // purchaseFlow::rollbackを呼び出し, 購入処理をロールバックする.
                // 在庫・ポイントの戻しはエンティティ上の変更のため、その後に flush して保存する
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
     * Webhook 処理を行う
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

        $json = $request->getContent();
        $data = json_decode($json, true);
        $orderNo = $this->elepayHelper->parseOrderNo($data['data']['object']['orderNo']);
        $chargeId = $data['data']['object']['id'];

        $this->logger->info('[注文確認] Order No :', [$orderNo]);

        /** @var Order $order */
        $order = $this->elepayHelper->getOrderByNo($orderNo);

        if (empty($order)) {
            $this->logger->error('[注文確認] 購入処理中の受注が存在しません.');
            $result = 'error';
        } else if ($this->isOrderSettled($order)) {
            $result = 'already_paid';
        } else {
            $chargeObject = $this->elepayHelper->getChargeObject($chargeId);
            $result = $this->orderValidate($order, $chargeObject);
        }

        if ($result === 'already_paid') {
            // 顧客の戻りや Webhook の再送で既に確定済みの受注は、完了処理を繰り返さずに成功を返して elepay の再送を止める.
            // カートは確定させたリクエストが削除済みのため触らない
            $this->logger->info('[注文確認] 受注は既に確定済みです.', [$order->getOrderNo()]);
            $result = 'success';
        } else if ($result === 'success') {
            // Webhook は顧客のセッションを持たないため、受注と同じ pre_order_id のカートを削除する
            $this->elepayHelper->removeCartByPreOrderId($order->getPreOrderId());
            $this->logger->info('[注文完了] 注文完了.');
        }

        $this->logger->info('*****  Elepay Webhook end.  *****');
        return new Response($result, $result === 'success' ? 200 : 400);
    }

    /**
     * Refund Validate
     *
     * @Route("/elepay_admin_redirect", name="elepay_refund_validate")
     *
     * @param Request $request
     *
     * @return RedirectResponse
     * @throws Exception
     */
    #[Route('/elepay_admin_redirect', name: 'elepay_refund_validate')]
    public function adminRedirect(Request $request): RedirectResponse
    {
        $chargeId = $request->query->get('chargeId');
        $chargeObject = $this->elepayHelper->getChargeObject($chargeId);
        $redirectUrl = $this->eccubeConfig->get('elepay.admin_host') .
            '/apps/' . $chargeObject['appId'] . '/gw/payment/charges/' . $chargeId;
        return $this->redirect($redirectUrl);
    }

    /**
     * 決済を開始したブラウザ以外へ elepay から戻った顧客に、決済が完了したことだけを伝える
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
     * elepay から戻った URL のパラメータで決済を取得する. 取得できない場合は null
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
     * @return bool 決済が確定済みで、この受注のものである場合 true
     */
    private function chargeMatchesOrder(Order $order, array $chargeObject): bool
    {
        return ($chargeObject['status'] ?? null) === 'captured'
            && $this->elepayHelper->parseOrderNo($chargeObject['orderNo'] ?? '') == $order->getOrderNo();
    }

    /**
     * 決済を開始したブラウザを購入完了画面へ遷移させる
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
     * 決済アプリから別のブラウザへ戻った場合など、決済を開始したブラウザ以外に決済完了だけを伝える.
     * 呼び出し側で、この受注の決済が確定済みであることを elepay の API で確認していること
     *
     * @param Order $order
     * @return RedirectResponse
     */
    private function redirectToPaidNotice(Order $order): RedirectResponse
    {
        $this->logger->info('[注文確認] 決済を開始したブラウザではないため購入完了画面を表示しません.', [$order->getOrderNo()]);
        // 決済を開始したブラウザのカートで再購入されないよう削除する
        $this->elepayHelper->removeCartByPreOrderId($order->getPreOrderId());
        return $this->redirectToRoute('elepay_paid');
    }

    /**
     * 顧客のブラウザから来たリクエストで、購入完了画面へ遷移する前の後処理を行う
     *
     * @param Order $order
     */
    private function orderComplete(Order $order)
    {
        $this->logger->info('[注文処理] カートをクリアします.', [$order->getOrderNo()]);
        $this->elepayHelper->cartClear();

        $session = $this->requestStack->getSession();
        // 完了後に戻り URL を再読み込みされても、その時点のカートを消さないよう決済中の印を外す
        $session->remove(self::SESSION_CHECKOUT_ORDER_ID);
        // 購入完了画面は SESSION_ORDER_ID を受注 ID として検索するため、受注番号ではなく ID を保存する
        $session->set(OrderHelper::SESSION_ORDER_ID, $order->getId());
    }

    /**
     * 決済による確定が済んだ受注か. 入金済みに加え、その後に管理画面で発送済み・キャンセル等へ進んだ受注も含む
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
     * 受注と elepay の決済を照合し、一致すれば受注を入金済みにする
     *
     * @param Order $order
     * @param array $chargeObject
     * @return string success: この呼び出しで入金済みにした / already_paid: 既に確定済み / error: 照合に失敗
     */
    private function orderValidate(Order $order, array $chargeObject): string
    {
        if ($this->isOrderSettled($order)) {
            return 'already_paid';
        }

        $orderNo = $order->getOrderNo();
        $chargeOrderNo = $this->elepayHelper->parseOrderNo($chargeObject['orderNo']);
        if ($orderNo != $chargeOrderNo) {
            $this->logger->error('[注文確認] ERROR::Verify payment order error.' . PHP_EOL . '  ec[order_no] : ' . $orderNo . ' / elepay[order_no] : ' . $chargeOrderNo);
            return 'error';
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

        // Webhook と顧客の戻りが同時に来ると両方が未確定の受注を読むため、
        // 確定処理（購入フローの確定・注文メール）を行うリクエストを DB 上で 1 つに絞る
        if ($this->elepayHelper->transitionOrderStatus($order, [OrderStatus::PENDING], OrderStatus::PAID)) {
            $revived = false;
        } else if ($this->elepayHelper->transitionOrderStatus($order, [OrderStatus::PROCESSING], OrderStatus::PAID)) {
            // 決済中のキャンセルが先に処理され、在庫・ポイントが戻された後に決済が確定した. 支払いは受け付ける
            $revived = true;
        } else {
            return 'already_paid';
        }

        $this->settleOrder($order, $paymentMethodName, $chargeObject['id'], $revived);

        return 'success';
    }

    /**
     * 受注を入金済みとして確定し、注文メールを送信する
     *
     * @param Order $order
     * @param string|null $paymentMethodName elepay 上の決済手段名. 決済を経由しない場合は null
     * @param string|null $chargeId
     * @param bool $revived キャンセルで購入処理中へ戻された受注を確定する場合 true
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
            // EC-CUBE はリクエストのトランザクションを rollback-only の場合にしかロールバックしない.
            // 入金済みへの遷移だけがコミットされ、確定処理の抜けた受注が残らないようにする
            if ($connection->isTransactionActive()) {
                $connection->setRollbackOnly();
            }
            throw $e;
        }

        // SMTP の失敗で決済の確定まで取り消さないよう、例外は記録するだけにする.
        // ただしメール履歴の保存失敗などでトランザクションが rollback-only になった場合は確定もコミットされないため、
        // 成功として応答しないよう例外をそのまま投げる
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
     * キャンセルで戻された在庫・ポイント等を、購入フローの prepare で確保し直す.
     * 在庫切れ等で確保できなくても支払いは受け付けるため、ショップが受注を確認できるよう警告を記録する
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
     * 購入フローを確定させて受注を入金済みにする
     *
     * @param Order $order
     * @param string|null $paymentMethodName elepay 上の決済手段名. 決済を経由しない場合は null
     * @param string|null $chargeId
     */
    private function markOrderPaid(Order $order, ?string $paymentMethodName = null, ?string $chargeId = null): void
    {
        // purchaseFlow::commitを呼び出し, 購入処理を完了させる.
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
