<?php

namespace Plugin\Elepay\Controller;

require_once(__DIR__ . '/../Resource/vendor/autoload.php');

use DateTime;
use Eccube\Common\EccubeConfig;
use Eccube\Event\EccubeEvents;
use Eccube\Event\EventArgs;
use Eccube\Service\PurchaseFlow\PurchaseContext;
use Eccube\Service\PurchaseFlow\PurchaseFlow;
use Elepay\ApiException;
use Exception;
use Eccube\Service\OrderHelper;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Eccube\Controller\AbstractController;
use Eccube\Entity\Order;
use Eccube\Entity\Master\OrderStatus;
use InvalidArgumentException;
use Plugin\Elepay\Service\ElepayHelper;
use Plugin\Elepay\Service\LoggerService;

class ElepayController extends AbstractController
{
    /**
     * @var EccubeConfig
     */
    protected $eccubeConfig;

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

    public function __construct(
        EccubeConfig $eccubeConfig,
        ElepayHelper $elepayHelper,
        LoggerService $loggerService,
        PurchaseFlow $shoppingPurchaseFlow
    ) {
        $this->eccubeConfig = $eccubeConfig;
        $this->elepayHelper = $elepayHelper;
        $this->logger = $loggerService;
        $this->purchaseFlow = $shoppingPurchaseFlow;
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
    public function checkout(Request $request)
    {
        /** @var Order $order */
        $order = $this->elepayHelper->getCartOrder();
        if (empty($order)) {
            $this->logger->error('[注文確認] 購入処理中の受注が存在しません.');
            return $this->redirectToRoute('shopping_error');
        }

        if ((integer)$order->getPaymentTotal() === 0) {
            // When the payment amount is 0, directly modify the order status to paid
            /** @var OrderStatus $orderStatus */
            $orderStatus = $this->elepayHelper->getOrderStatusPaid();
            $order->setOrderStatus($orderStatus);
            $order->setPaymentDate(new DateTime());
            $this->entityManager->flush();

            // Clear shopping cart
            $this->logger->info('[注文処理] カートをクリアします.', [$order->getOrderNo()]);
            $this->elepayHelper->cartClear();

            // Save the order ID into Session. The shopping_complete page needs Session to get the order
            $this->session->set(OrderHelper::SESSION_ORDER_ID, $order->getOrderNo());

            return $this->redirectToRoute('shopping_complete');
        }

        $basePath = $request->getBasePath() ? $request->getBasePath() : '/';
        $checkoutValidateUrl = $this->elepayHelper->addQuery(
            $request->server->get('HTTP_ORIGIN') . $basePath . 'elepay_checkout_validate',
            [
                'orderNo' => $this->elepayHelper->getOrderNo($order)
            ]
        );
        $codeExtra = [
            'cartKey' => $this->elepayHelper->getCartKey()
        ];

        try {
            $paymentObject = $this->elepayHelper->createCodeObject($order, $checkoutValidateUrl, $codeExtra);
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

        /** @var OrderStatus $orderStatusPaid */
        $orderStatusPaid = $this->elepayHelper->getOrderStatusPaid();
        $orderStatus = $order->getOrderStatus();

        if ($orderStatus === $orderStatusPaid) {
            $this->orderComplete($order);
            return $this->redirectToRoute('shopping_complete');
        }

        if ($status === 'captured') {
            try {
                if (!empty($chargeId)) {
                    $chargeObject = $this->elepayHelper->getChargeObject($chargeId);
                } else if (!empty($codeId)) {
                    $codeObject = $this->elepayHelper->getCodeObject($codeId);
                    $chargeObject = $codeObject['charge'];
                }
            } catch (ApiException $e) {
                $this->logger->error('[注文確認] Exception when calling CodeApi->retrieveCode::' . $e->getMessage(), [$order->getOrderNo()]);
                return $this->redirectToRoute('shopping_error');
            } catch (InvalidArgumentException $e) {
                $this->logger->error('[注文処理] Exception when calling CodeApi->retrieveCode::' . $e->getMessage(), [$order->getOrderNo()]);
                return $this->redirectToRoute('shopping_error');
            }

            if (empty($chargeObject)) {
                $this->logger->error('[注文処理] ChangeObject is empty', [$order->getOrderNo()]);
                return $this->redirectToRoute('shopping_error');
            }

            $result = $this->orderValidate($order, $chargeObject);

            if ($result === 'success') {
                $this->orderComplete($order);
                return $this->redirectToRoute('shopping_complete');
            } else {
                return $this->redirectToRoute('shopping_error');
            }
        } else {
            // Roll back the order status
            /** @var OrderStatus $orderStatusProcessing */
            $orderStatusProcessing = $this->elepayHelper->getOrderStatusProcessing();
            $order->setOrderStatus($orderStatusProcessing);
            $this->entityManager->flush();

            // purchaseFlow::rollbackを呼び出し, 購入処理をロールバックする.
            $this->purchaseFlow->rollback($order, new PurchaseContext());

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
    public function elepayWebhook(Request $request)
    {
        $this->logger->info('*****  Elepay Webhook start.  ***** ');

        $json = $request->getContent();
        $data = json_decode($json, true);
        $cartKey = $data['data']['object']['extra']['cartKey'];
        $orderNo = $this->elepayHelper->parseOrderNo($data['data']['object']['orderNo']);
        $chargeId = $data['data']['object']['id'];
        $chargeObject = $this->elepayHelper->getChargeObject($chargeId);

        $this->logger->info('[注文確認] Order No :', [$orderNo]);

        /** @var Order $order */
        $order = $this->elepayHelper->getOrderByNo($orderNo);

        if (!empty($order)) {
            $result = $this->orderValidate($order, $chargeObject);
        } else {
            $this->logger->error('[注文確認] 購入処理中の受注が存在しません.');
            $result = 'error';
        }

        if ($result === 'success') {
            $event = new EventArgs(
                [
                    'Order' => $order,
                ],
                $request
            );
            $this->eventDispatcher->dispatch(EccubeEvents::FRONT_SHOPPING_COMPLETE_INITIALIZE, $event);
            $this->orderComplete($order, $cartKey);
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
    public function adminRedirect(Request $request): RedirectResponse
    {
        $chargeId = $request->query->get('chargeId');
        $chargeObject = $this->elepayHelper->getChargeObject($chargeId);
        $redirectUrl = $this->eccubeConfig->get('elepay.admin_host') .
            '/apps/' . $chargeObject['appId'] . '/gw/payment/charges/' . $chargeId;
        return $this->redirect($redirectUrl);
    }

    private function orderComplete (Order $order, string $cartKey = null)
    {
        // Clear shopping cart
        $this->logger->info('[注文処理] カートをクリアします.', [$order->getOrderNo()]);
        $this->elepayHelper->cartClear($cartKey);

        // Save the order ID into Session. The shopping_complete screen needs Session to get the order
        $this->session->set(OrderHelper::SESSION_ORDER_ID, $order->getOrderNo());
    }

    private function orderValidate(Order $order, array $chargeObject)
    {
        /** @var OrderStatus $orderStatusPaid */
        $orderStatusPaid = $this->elepayHelper->getOrderStatusPaid();
        $orderStatus = $order->getOrderStatus();

        if ($orderStatus === $orderStatusPaid) {
            return 'success';
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

        // purchaseFlow::commitを呼び出し, 購入処理を完了させる.
        // The purpose of this operation is to increase the order_date of the order,
        // but it also changes the order status to new,
        // so it must be executed before the order status changes
        $this->purchaseFlow->commit($order, new PurchaseContext());

        // Change the order status to paid
        $order->setOrderStatus($orderStatusPaid);
        $order->setPaymentDate(new DateTime());
        $order->setPaymentMethod($paymentMethodName);
        $order->setElepayChargeId($chargeObject['id']); // If paying using a widget, need to save the chargeId here
        $this->entityManager->flush();

        // メール送信
        $this->logger->info('[注文処理] 注文メールの送信を行います.', [$order->getOrderNo()]);
        $this->elepayHelper->sendOrderMail($order);

        return 'success';
    }
}
