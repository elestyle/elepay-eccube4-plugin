<?php

namespace Plugin\elepay42;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Common\EccubeConfig;
use Symfony\Component\Filesystem\Filesystem;
use Psr\Container\ContainerInterface;
use Eccube\Plugin\AbstractPluginManager;
use Eccube\Entity\Payment;
use Eccube\Entity\PaymentOption;
use Eccube\Entity\Delivery;
use Eccube\Entity\Layout;
use Eccube\Entity\Page;
use Eccube\Entity\PageLayout;
use Eccube\Repository\PaymentRepository;
use Eccube\Repository\PaymentOptionRepository;
use Eccube\Repository\DeliveryRepository;
use Plugin\elepay42\Repository\ConfigRepository;
use Plugin\elepay42\Entity\Config;
use Plugin\elepay42\Service\Method\Elepay;

class PluginManager extends AbstractPluginManager
{
    /**
     * プラグインが追加するフロントのページ. file_name は管理画面のページ編集でテンプレートを読むために使われる
     */
    private const PAGES = [
        ['url' => 'elepay_paid', 'name' => 'elepay決済完了', 'file_name' => '@elepay42/default/Shopping/paid'],
    ];

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
    public function install(array $config, ContainerInterface $container): void
    {
        // リソースファイルのコピー
//        $this->copyAssets();
    }

    /**
     * 有効なままバージョンアップされた場合も、新しいバージョンで追加したページを登録する
     *
     * @param array $config
     * @param ContainerInterface $container
     */
    public function update(array $config, ContainerInterface $container): void
    {
        $this->registerPage($container);
    }

    /**
     * @param array $config
     * @param ContainerInterface $container
     */
    public function uninstall(array $config, ContainerInterface $container): void
    {
        // リソースファイルの削除
//        $this->removeAssets();
        $this->removePaymentMethod($container);
        $this->removePage($container);
    }

    /**
     * @param array $config
     * @param ContainerInterface $container
     */
    public function enable(array $config, ContainerInterface $container): void
    {
        $this->registerPluginConfig($container);
        $this->registerPaymentMethod($container, $config);
        $this->enablePaymentMethod($container);
        $this->registerPage($container);
    }

    /**
     * @param array $config
     * @param ContainerInterface $container
     */
    public function disable(array $config, ContainerInterface $container): void
    {
        $this->disablePaymentMethod($container);
    }

    /**
     * Register the default plugin configuration
     *
     * @param ContainerInterface $container
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
     */
    public function enablePaymentMethod(ContainerInterface $container): void
    {
        $this->setPaymentVisible($container, true);
    }

    /**
     * Disable payment methods
     *
     * @param ContainerInterface $container
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
     * フロントのページとして登録する. 未登録のルートは下層ページのレイアウト（ヘッダー・フッター）が適用されないため
     *
     * @param ContainerInterface $container
     */
    private function registerPage(ContainerInterface $container): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get('doctrine')->getManager();

        foreach (self::PAGES as $pageInfo) {
            if ($entityManager->getRepository(Page::class)->findOneBy(['url' => $pageInfo['url']]) !== null) {
                continue;
            }

            $page = new Page();
            $page
                ->setName($pageInfo['name'])
                ->setUrl($pageInfo['url'])
                ->setFileName($pageInfo['file_name'])
                ->setEditType(Page::EDIT_TYPE_DEFAULT)
                ->setMetaRobots('noindex');
            $entityManager->persist($page);
            $entityManager->flush();

            /** @var Layout $layout */
            $layout = $entityManager->find(Layout::class, Layout::DEFAULT_LAYOUT_UNDERLAYER_PAGE);

            $pageLayout = new PageLayout();
            $pageLayout
                ->setPage($page)
                ->setPageId($page->getId())
                ->setLayout($layout)
                ->setLayoutId($layout->getId())
                ->setSortNo(0);
            $entityManager->persist($pageLayout);
            $entityManager->flush();
        }
    }

    /**
     * @param ContainerInterface $container
     */
    private function removePage(ContainerInterface $container): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get('doctrine')->getManager();

        foreach (self::PAGES as $pageInfo) {
            $page = $entityManager->getRepository(Page::class)->findOneBy(['url' => $pageInfo['url']]);
            if ($page === null) {
                continue;
            }
            foreach ($entityManager->getRepository(PageLayout::class)->findBy(['page_id' => $page->getId()]) as $pageLayout) {
                $entityManager->remove($pageLayout);
            }
            $entityManager->remove($page);
            $entityManager->flush();
        }
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
}
