<?php

namespace Plugin\elepay42;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Eccube\Common\EccubeConfig;
use Symfony\Component\Filesystem\Filesystem;
use Psr\Container\ContainerInterface;
use Eccube\Plugin\AbstractPluginManager;
use Eccube\Entity\Payment;
use Eccube\Entity\PaymentOption;
use Eccube\Entity\Delivery;
use Eccube\Repository\PaymentRepository;
use Eccube\Repository\PaymentOptionRepository;
use Eccube\Repository\DeliveryRepository;
use Plugin\elepay42\Repository\ConfigRepository;
use Plugin\elepay42\Entity\Config;
use Plugin\elepay42\Service\Method\Elepay;

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
            $entityManager->flush();
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
        $payment = $this->findPayment($entityManager);

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

            $eccubeConfig = $container->get(EccubeConfig::class);

            // The payment name is initialized only on creation,
            // so that a name edited on the admin screen survives re-enabling
            $payment = new Payment();
            $payment
                ->setMethodClass(Elepay::class)
                ->setMethod($eccubeConfig->get('elepay.name'))
                ->setSortNo($sortNo)
                ->setCharge(0)
                ->setVisible(false);
        }

        $entityManager->persist($payment);
        $entityManager->flush();

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
            $entityManager->flush();
        }
    }

    /**
     * Remove payment method
     * The record itself is kept and only hidden, because past order data refers to it.
     * Its delivery bindings are dropped so that the remaining record can be deleted
     * on the admin screen without leaving rows behind in dtb_payment_option.
     *
     * @param ContainerInterface $container
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function removePaymentMethod(ContainerInterface $container): void
    {
        /** @var EntityManager $entityManager */
        $entityManager = $container->get('doctrine')->getManager();

        $payment = $this->findPayment($entityManager);
        if (is_null($payment)) {
            return;
        }

        foreach ($payment->getPaymentOptions() as $paymentOption) {
            $entityManager->remove($paymentOption);
        }

        $payment->setVisible(false);
        $entityManager->persist($payment);
        $entityManager->flush();
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
        $this->setPaymentVisible($container, true);
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
        $this->setPaymentVisible($container, false);
    }

    /**
     * Switch the display state of the payment method
     *
     * @param ContainerInterface $container
     * @param bool $visible
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private function setPaymentVisible(ContainerInterface $container, bool $visible): void
    {
        /** @var EntityManager $entityManager */
        $entityManager = $container->get('doctrine')->getManager();

        $payment = $this->findPayment($entityManager);
        if (is_null($payment)) {
            return;
        }

        $payment->setVisible($visible);
        $entityManager->persist($payment);
        $entityManager->flush();
    }

    /**
     * Find the payment method of this plugin
     * Returns null when the record has been deleted on the admin screen
     *
     * @param EntityManagerInterface $entityManager
     * @return Payment|null
     */
    private function findPayment(EntityManagerInterface $entityManager): ?Payment
    {
        /** @var PaymentRepository $paymentRepository */
        $paymentRepository = $entityManager->getRepository(Payment::class);

        return $paymentRepository->findOneBy(['method_class' => Elepay::class]);
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
