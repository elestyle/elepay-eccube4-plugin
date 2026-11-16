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
     * Returns false, since nothing is verified on the confirmation page.
     * Returning a successful PaymentResult makes EC-CUBE 4.4 call getResponse() with no response set, and crash.
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
        // Change the order status to PENDING
        /** @var OrderStatus $orderStatus */
        $orderStatus = $this->orderStatusRepository->find(OrderStatus::PENDING);
        $this->order->setOrderStatus($orderStatus);

        // Call purchaseFlow::prepare to proceed with the purchase.
        $this->purchaseFlow->prepare($this->order, new PurchaseContext());

        // Forward, within the same request, to the relay page that moves on to the elepay payment page.
        // With a redirect, EC-CUBE 4.2/4.3 delay the transaction commit until kernel.terminate,
        // and the relay page would read the order before it becomes PENDING.
        // EC-CUBE 4.4 reads the dispatcher's response without a null check, so set a dummy response that is neither a redirect
        // nor 2xx, which sends every version down the isForward() branch
        $dispatcher = new PaymentDispatcher();
        $dispatcher->setResponse(new Response('', Response::HTTP_CONTINUE));
        $dispatcher->setForward(true);
        $dispatcher->setRoute('elepay_checkout');

        return $dispatcher;
    }

    /**
     * {@inheritdoc}
     *
     * Never reached, since apply() forwards out of the purchase flow.
     * Returns a failure so that, should it ever be reached, an unpaid order is not treated as complete.
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
