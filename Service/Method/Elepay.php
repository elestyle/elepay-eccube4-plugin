<?php

namespace Plugin\Elepay\Service\Method;

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
     */
    public function verify()
    {
        /** @var PaymentResult $paymentResult */
        $paymentResult = new PaymentResult();
        $paymentResult->setSuccess(true);
        return $paymentResult;
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

        // POSTを行うため中継のページを表示
        $dispatcher = new PaymentDispatcher();
        $dispatcher->setForward(true);
        $dispatcher->setRoute('elepay_checkout');

        return $dispatcher;
    }

    /**
     * {@inheritdoc}
     */
    public function checkout()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function setFormType(FormInterface $form)
    {
        $this->form = $form;
    }

    /**
     * {@inheritdoc}
     *
     * @param Order $order
     */
    public function setOrder(Order $order)
    {
        $this->order = $order;
    }
}
 ?>
