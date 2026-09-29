<?php

namespace Plugin\elepay42\Service\Method;

use Eccube\Service\PurchaseFlow\PurchaseException;
use Eccube\Entity\Order;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Repository\Master\OrderStatusRepository;
use Eccube\Service\Payment\PaymentDispatcher;
use Eccube\Service\Payment\PaymentMethodInterface;
use Eccube\Service\Payment\PaymentResult;
use Eccube\Service\PurchaseFlow\PurchaseContext;
use Eccube\Service\PurchaseFlow\PurchaseFlow;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;

class Elepay implements PaymentMethodInterface
{
    /**
     * @var OrderStatusRepository
     */
    private $orderStatusRepository;

    /**
     * @var PurchaseFlow
     */
    private $purchaseFlow;

    /**
     * @var FormInterface
     */
    private $form;

    /**
     * @var Order
     */
    private $order;

    /**
     * elepay constructor.
     *
     * @param OrderStatusRepository $orderStatusRepository
     * @param PurchaseFlow $shoppingPurchaseFlow
     */
    public function __construct(
        OrderStatusRepository $orderStatusRepository,
        PurchaseFlow $shoppingPurchaseFlow
    ) {
        $this->orderStatusRepository = $orderStatusRepository;
        $this->purchaseFlow = $shoppingPurchaseFlow;
    }

    /**
     * {@inheritdoc}
     *
     * 確認画面で検証することはないため false を返す.
     * 成功の PaymentResult を返すと、EC-CUBE 4.4 はレスポンス未設定のまま getResponse() を参照して落ちる.
     */
    public function verify(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * @return PaymentDispatcher
     * @throws PurchaseException
     */
    public function apply(): PaymentDispatcher
    {
        // 受注ステータスを決済処理中へ変更
        /** @var OrderStatus $orderStatus */
        $orderStatus = $this->orderStatusRepository->find(OrderStatus::PENDING);
        $this->order->setOrderStatus($orderStatus);

        // purchaseFlow::prepareを呼び出し, 購入処理を進める.
        $this->purchaseFlow->prepare($this->order, new PurchaseContext());

        // elepay の決済画面へ遷移する中継ページへ、同一リクエスト内で forward する.
        // リダイレクトにすると、EC-CUBE 4.2/4.3 ではトランザクションのコミットが kernel.terminate まで遅れ、
        // 中継ページが PENDING になる前の受注を読んでしまう.
        // EC-CUBE 4.4 は dispatcher のレスポンスを null チェックせずに参照するため、リダイレクトでも 2xx でもない
        // ダミーのレスポンスを設定し、全バージョンで isForward() の分岐に進ませる
        $dispatcher = new PaymentDispatcher();
        $dispatcher->setResponse(new Response('', Response::HTTP_CONTINUE));
        $dispatcher->setForward(true);
        $dispatcher->setRoute('elepay_checkout');

        return $dispatcher;
    }

    /**
     * {@inheritdoc}
     *
     * apply() が forward して購入フローを抜けるため、ここへは到達しない.
     * 万一到達した場合に未決済の受注が完了扱いにならないよう、失敗を返す.
     */
    public function checkout(): PaymentResult
    {
        $paymentResult = new PaymentResult();
        $paymentResult->setSuccess(false);
        $paymentResult->setErrors(['front.shopping.system_error']);
        return $paymentResult;
    }

    /**
     * {@inheritdoc}
     */
    public function setFormType(FormInterface $form): PaymentMethodInterface
    {
        $this->form = $form;
        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @param Order $order
     */
    public function setOrder(Order $order): PaymentMethodInterface
    {
        $this->order = $order;
        return $this;
    }
}
 ?>
