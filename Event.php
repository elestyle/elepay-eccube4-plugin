<?php

namespace Plugin\Elepay;

use Eccube\Common\EccubeConfig;
use Eccube\Entity\Order;
use Eccube\Entity\Payment;
use Eccube\Event\EccubeEvents;
use Eccube\Event\EventArgs;
use Eccube\Event\TemplateEvent;
use Eccube\Repository\PaymentRepository;
use Elepay\ApiException;
use Knp\Component\Pager\Pagination\SlidingPagination;
use Knp\Component\Pager\PaginatorInterface;
use Plugin\Elepay\Entity\Config;
use Plugin\Elepay\Service\ElepayHelper;
use SunCat\MobileDetectBundle\DeviceDetector\MobileDetector;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Plugin\Elepay\Repository\ConfigRepository;
use Plugin\Elepay\Service\Method\Elepay;

class Event implements EventSubscriberInterface
{
    /**
     * @var PaginatorInterface
     */
    protected $paginator;

    /**
     * @var ElepayHelper
     */
    protected $elepayHelper;

    /**
     * @var EccubeConfig
     */
    protected $eccubeConfig;

    /**
     * @var PaymentRepository
     */
    protected $paymentRepository;

    /**
     * @var ConfigRepository
     */
    protected $configRepository;

    /**
     * @var Config|null
     */
    protected $config;

    /**
     * @var object|null
     */
    private $elepayIdMap;

    public function __construct(
        PaginatorInterface $paginator,
        ElepayHelper $elepayHelper,
        EccubeConfig $eccubeConfig,
        PaymentRepository $paymentRepository,
        ConfigRepository $configRepository
    ) {
        $this->paginator = $paginator;
        $this->elepayHelper = $elepayHelper;
        $this->eccubeConfig = $eccubeConfig;
        $this->paymentRepository = $paymentRepository;
        $this->config = $configRepository->get();
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        // Define the events corresponding to the template
        // When the template is rendered, it trigger the corresponding event
        return [
            'Shopping/index.twig' => 'index',
            'Shopping/confirm.twig' => 'confirm',
            '@admin/Order/index.twig' => 'order',
            EccubeEvents::ADMIN_ORDER_INDEX_SEARCH => 'orderSearch'
        ];
    }

    /**
     * Payment method select page
     *
     * @param TemplateEvent $event
     */
    public function index(TemplateEvent $event): void
    {
        /** @var Payment $payment */
        $payment = $this->paymentRepository->findOneBy([
            'method_class' => Elepay::class,
            'visible' => true
        ]);

        if (empty($payment)) return;

        $paymentMethods = $this->elepayHelper->getPaymentMethods();

        if (empty($paymentMethods)) return;

        $parameters = [
            'payment_id' => $payment->getId(),
            'payment_methods' => $paymentMethods
        ];

        $event->setParameters(array_merge($event->getParameters(), $parameters));
        $event->addSnippet('@Elepay/default/Shopping/info.twig');
    }

    /**
     * Payment confirmation page
     *
     * @param TemplateEvent $event
     */
    public function confirm(TemplateEvent $event): void
    {
        /** @var Order $order */
        $order = $this->elepayHelper->getCartOrder();
        if ($order) {
            $payment = $order->getPayment();
            $parameters = [
                'payment_method' => $payment->getMethod()
            ];
            $event->setParameters(array_merge($event->getParameters(), $parameters));
        }
        $event->addSnippet('@Elepay/default/Shopping/confirm_button.twig');
    }

    /**
     * Admin Order list page
     * Mount the Twig template
     *
     * @param TemplateEvent $event
     */
    public function order(TemplateEvent $event): void
    {
        if ($this->elepayIdMap === null) return;
        $parameters = [
            'elepay_id_map' => $this->elepayIdMap
        ];
        $event->setParameters(array_merge($event->getParameters(), $parameters));
        $event->addSnippet('@Elepay/admin/order.twig');
        $this->elepayIdMap = null;
    }

    /**
     * Admin Order list page
     *
     * @param EventArgs $event
     */
    public function orderSearch(EventArgs $event): void
    {
        $session = $event->getRequest()->getSession();
        $page_no = $session->get('eccube.admin.order.search.page_no', 1);
        $page_count = $session->get('eccube.admin.order.search.page_count', $this->eccubeConfig->get('eccube_default_page_count'));
        $qb = $event['qb'];
        /** @var SlidingPagination $pagination */
        $pagination = $this->paginator->paginate(
            $qb,
            $page_no,
            $page_count
        );
        $items = $pagination->getItems();
        $elepayIdMap = [];
        /** @var Order $order */
        foreach ($items as $order) {
            $elepay_charge_id = $order->getElepayChargeId();
            if (empty($elepay_charge_id)) continue;
            try {
                $chargeObject = $this->elepayHelper->getChargeObject($elepay_charge_id);
                switch ($chargeObject['status']) {
                    case 'captured':
                    case 'refunded':
                    case 'partially_refunded':
                        $elepayIdMap[$order->getId()] = $elepay_charge_id;
                        break;
                }
            } catch (ApiException $err) {}
        }
        $this->elepayIdMap = $elepayIdMap;
    }

    /**
     * @param TemplateEvent $event
     */
//    public function logo(TemplateEvent $event): void
//    {
//        /** @var string $selectedNumber */
//        $selectedNumber = $this->config->getLogo();
//        $parameters['Elepay']['logo'] = $this->eccubeConfig->get("elepay.elepay_express_elepay_logo_${selectedNumber}");
//        $event->setParameters(array_merge($event->getParameters(), $parameters));
//    }
}
