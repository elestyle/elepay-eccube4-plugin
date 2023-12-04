<?php

namespace Plugin\Elepay;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Eccube\Common\EccubeConfig;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Eccube\Plugin\AbstractPluginManager;
use Eccube\Entity\Payment;
use Eccube\Entity\PaymentOption;
use Eccube\Entity\Delivery;
use Eccube\Repository\PaymentRepository;
use Eccube\Repository\PaymentOptionRepository;
use Eccube\Repository\DeliveryRepository;
use Plugin\Elepay\Repository\ConfigRepository;
use Plugin\Elepay\Entity\Config;
use Plugin\Elepay\Service\Method\Elepay;

class PluginManager extends AbstractPluginManager
{
    /**
     * @var string
     */
    private $origin_dir;

    /**
     * @var string
     */
    private $target_dir;

    /**
     * PluginManager constructor.
     */
    public function __construct()
    {
        // Define a copy source directory and a copy target directory
        $this->origin_dir = __DIR__ . '/Resource/assets/img';
        $this->target_dir = __DIR__ . '/../../../html/template/default/assets/img/elepay';
    }

    /**
     * @param array $config
     * @param ContainerInterface $container
     */
    public function install(array $config, ContainerInterface $container)
    {
        // リソースファイルのコピー
//        $this->copyAssets();
    }

    /**
     * @param array $config
     * @param ContainerInterface $container
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function uninstall(array $config, ContainerInterface $container)
    {
        // リソースファイルの削除
//        $this->removeAssets();
        $this->removePaymentMethod($container);
    }

    /**
     * @param array $config
     * @param ContainerInterface $container
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function enable(array $config, ContainerInterface $container)
    {
        $this->registerPluginConfig($container);
        $this->registerPaymentMethod($container, $config);
        $this->enablePaymentMethod($container);
    }

    /**
     * @param array $config
     * @param ContainerInterface $container
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function disable(array $config, ContainerInterface $container)
    {
        $this->disablePaymentMethod($container);
    }

    /**
     * Register the default plugin configuration
     *
     * @param ContainerInterface $container
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private function registerPluginConfig(ContainerInterface $container): void
    {
        /** @var EntityManager $entityManager */
        $entityManager = $container->get('doctrine')->getManager();

        /** @var Config $config */
        $config = $entityManager->find(Config::class, 1);

        if (empty($config)) {
            /** @var Config $Config */
            $config = Config::createInitialConfig();
            $entityManager->persist($config);
            $entityManager->flush($config);
        }
    }

    /**
     * Register payment method
     *
     * @param ContainerInterface $container
     * @param array $config
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function registerPaymentMethod(ContainerInterface $container, $config)
    {
        /** @var EntityManager $entityManager */
        $entityManager = $container->get('doctrine')->getManager();
        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = $entityManager->getRepository(Payment::class);

        // Check that the payment method is registered in the dtb_payment table in the database
        /** @var Payment $payment */
        $payment = $paymentRepository->findOneBy(['method_class' => Elepay::class]);

        if (empty($payment)) {
            // Get the largest payment method of Rank other than Elepay
            // The larger the rank is, the more advanced the page appears
            $topPayment = $paymentRepository
                ->createQueryBuilder('payment')
                //->where('payment.method_class != :class_name')
                //->setParameter('class_name', Elepay::class)
                ->orderBy('payment.sort_no', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            // If found, set its sort_no to 1 higher than them
            $sortNo = $topPayment ? $topPayment->getSortNo() + 1 : 0;

            $payment = new Payment();
            $payment
                ->setMethodClass(Elepay::class)
                ->setSortNo($sortNo)
                ->setCharge(0);
        }

        $eccubeConfig = new EccubeConfig($container);
        $payment
            ->setMethod($eccubeConfig->get('elepay.name'))
            ->setVisible(false);

        $entityManager->persist($payment);
        $entityManager->flush($payment);

        // Bind existing delivery methods to payment methods
        /** @var DeliveryRepository $deliveryRepository */
        $deliveryRepository = $entityManager->getRepository(Delivery::class);
        /** @var Delivery $delivery */
        foreach ($deliveryRepository->findAll() as $delivery) {
            /** @var PaymentOptionRepository $paymentOptionRepository */
            $paymentOptionRepository = $entityManager->getRepository(PaymentOption::class);
            $paymentOption = $paymentOptionRepository->findOneBy([
                'delivery_id' => $delivery->getId(),
                'payment_id' => $payment->getId(),
            ]);
            if (!is_null($paymentOption)) {
                continue;
            }
            $paymentOption = new PaymentOption();
            $paymentOption
                ->setPayment($payment)
                ->setPaymentId($payment->getId())
                ->setDelivery($delivery)
                ->setDeliveryId($delivery->getId());
            $entityManager->persist($paymentOption);
            $entityManager->flush($paymentOption);
        }
    }

    /**
     * Remove payment method
     * When the payment method overpays, it is bound to the order data and cannot be deleted.
     * So don't do real delete, just do logical disable
     *
     * @param ContainerInterface $container
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function removePaymentMethod(ContainerInterface $container): void
    {
        /** @var EntityManager $entityManager */
        $entityManager = $container->get('doctrine')->getManager();
        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = $entityManager->getRepository(Payment::class);

        /** @var Payment $payment */
        $payment = $paymentRepository->findOneBy(['method_class' => Elepay::class]);
        $payment->setVisible(false);
        $entityManager->persist($payment);
        $entityManager->flush($payment);
    }

    /**
     * Enable payment method
     *
     * @param ContainerInterface $container
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function enablePaymentMethod(ContainerInterface $container): void
    {
        /** @var EntityManager $entityManager */
        $entityManager = $container->get('doctrine')->getManager();
        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = $entityManager->getRepository(Payment::class);

        /** @var Payment $payment */
        $payment = $paymentRepository->findOneBy(['method_class' => Elepay::class]);
        $payment->setVisible(true);
        $entityManager->persist($payment);
        $entityManager->flush($payment);
    }

    /**
     * Disable payment methods
     *
     * @param ContainerInterface $container
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function disablePaymentMethod(ContainerInterface $container): void
    {
        /** @var EntityManager $entityManager */
        $entityManager = $container->get('doctrine')->getManager();
        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = $entityManager->getRepository(Payment::class);

        /** @var Payment $payment */
        $payment = $paymentRepository->findOneBy(['method_class' => Elepay::class]);
        $payment->setVisible(false);
        $entityManager->persist($payment);
        $entityManager->flush($payment);
    }

    /**
     * Copy Resource Directories
     */
    private function copyAssets()
    {
        $file = new Filesystem();
        $file->mkdir($this->target_dir);
        $file->mirror($this->origin_dir, $this->target_dir);
    }

    /**
     * Delete Resource Directories
     */
    private function removeAssets()
    {
        $file = new Filesystem();
        $file->remove($this->target_dir);
    }

    /**
     * Open EntityManager
     *
     * @param EntityManager $entityManager
     * @return EntityManager
     * @throws ORMException
     */
    private function openEntityManager($entityManager)
    {
        if ($entityManager->isOpen()) {
            return $entityManager;
        } else {
            return $entityManager->create(
                $entityManager->getConnection(),
                $entityManager->getConfiguration(),
                $entityManager->getEventManager()
            );
        }
    }
}
