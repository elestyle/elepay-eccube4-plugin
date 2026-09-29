<?php

namespace Plugin\elepay42\Service;

require_once(__DIR__ . '/../Resource/vendor/autoload.php');

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\BaseInfo;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Eccube\Repository\BaseInfoRepository;
use Eccube\Repository\CartRepository;
use Eccube\Repository\Master\OrderStatusRepository;
use Eccube\Repository\OrderRepository;
use Eccube\Service\CartService;
use Eccube\Service\MailService;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Eccube\Common\EccubeConfig;
use Elepay\Api\CodeApi;
use Elepay\Api\ChargeApi;
use Elepay\Api\CodeSettingApi;
use Elepay\ApiException;
use Elepay\Configuration;
use Elepay\Model\CodeDto;
use Elepay\Model\CodeReq;
use Elepay\Model\ChargeDto;
use Elepay\Model\CodePaymentMethodResponse;
use Plugin\elepay42\Entity\Config;
use Plugin\elepay42\Repository\ConfigRepository;

class ElepayHelper
{
    /**
     * @var OrderRepository
     */
    protected $orderRepository;

    /**
     * @var OrderStatusRepository
     */
    protected $orderStatusRepository;

    /**
     * @var CartRepository
     */
    protected $cartRepository;

    /**
     * @var BaseInfoRepository
     */
    protected $baseInfoRepository;

    /**
     * @var ConfigRepository
     */
    protected $configRepository;

    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @var CartService
     */
    protected $cartService;

    /**
     * @var MailService
     */
    protected $mailService;

    /**
     * @var EccubeConfig
     */
    protected $eccubeConfig;

    /**
     * @var Client
     */
    protected $httpClient;

    /**
     * @var BaseInfo|null
     */
    protected $baseInfo;

    /**
     * @var Config|null
     */
    protected $config;

    /**
     * @var CodeApi
     */
    protected $codeApi;

    /**
     * @var ChargeApi
     */
    protected $chargeApi;

    /**
     * @var CodeSettingApi
     */
    protected $codeSettingApi;

    public function __construct(
        OrderRepository $orderRepository,
        OrderStatusRepository $orderStatusRepository,
        CartRepository $cartRepository,
        BaseInfoRepository $baseInfoRepository,
        ConfigRepository $configRepository,
        EntityManagerInterface $entityManager,
        CartService $cartService,
        MailService $mailService,
        EccubeConfig $eccubeConfig
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderStatusRepository = $orderStatusRepository;
        $this->cartRepository = $cartRepository;
        $this->entityManager = $entityManager;
        $this->baseInfo = $baseInfoRepository->get();
        $this->config = $configRepository->get();

        $this->cartService = $cartService;
        $this->mailService = $mailService;
        $this->eccubeConfig = $eccubeConfig;
        $this->httpClient = new Client();

        $secretKey = $this->config->getSecretKey();
        $elepayApiHost = $this->eccubeConfig->get('elepay.api_host');

        $config = Configuration::getDefaultConfiguration()
            ->setUsername($secretKey)
            ->setPassword('')
            ->setHost($elepayApiHost);

        $this->codeApi = new CodeApi(null, $config);
        $this->chargeApi = new ChargeApi(null, $config);
        $this->codeSettingApi = new CodeSettingApi(null, $config);
    }

    /**
     * 決済処理中の受注を取得する.
     *
     * @return null|object
     */
    public function getCartOrder()
    {
        $preOrderId = $this->cartService->getPreOrderId();

        return $this->orderRepository->findOneBy([
            'pre_order_id' => $preOrderId,
        ]);
    }

    /**
     * 現在のセッションのカートを空にする
     */
    public function cartClear()
    {
        $this->cartService->clear();
    }

    /**
     * 受注と同じ pre_order_id を持つカートを削除する. 既に削除済みの場合は何もしない
     *
     * @param string|null $preOrderId
     */
    public function removeCartByPreOrderId($preOrderId)
    {
        if (empty($preOrderId)) {
            return;
        }
        $cart = $this->cartRepository->findOneBy(['pre_order_id' => $preOrderId]);
        if ($cart !== null) {
            $this->entityManager->remove($cart);
            $this->entityManager->flush();
        }
    }

    /**
     * 受注ステータスを、現在のステータスが $fromStatusIds のいずれかである場合に限り $toStatusId へ変更する.
     *
     * 決済の確定とキャンセルが同時に起きても一方だけが後続処理を行えるよう、条件付き UPDATE で DB 上の
     * 状態遷移を 1 リクエストに絞る. 同じ行への後の UPDATE は先のトランザクションの終了まで行ロックで待ち、
     * 遷移済みの行には一致しないため false になる.
     * リクエスト全体のトランザクション内で呼ぶこと. 後続処理が失敗した場合は、この UPDATE ごとロールバックさせる必要がある.
     * 呼び出し側のエンティティは更新しないため、エンティティのステータスは呼び出し側で合わせる
     *
     * @param Order $order
     * @param int[] $fromStatusIds
     * @param int $toStatusId
     * @return bool この呼び出しで遷移させた場合 true
     */
    public function transitionOrderStatus(Order $order, array $fromStatusIds, int $toStatusId): bool
    {
        $affected = $this->entityManager->createQueryBuilder()
            ->update(Order::class, 'o')
            ->set('o.OrderStatus', ':to')
            ->where('o.id = :id')
            ->andWhere('o.OrderStatus IN (:from)')
            ->setParameter('to', $this->orderStatusRepository->find($toStatusId))
            ->setParameter('from', $fromStatusIds)
            ->setParameter('id', $order->getId())
            ->getQuery()
            ->execute();

        return $affected === 1;
    }

    /**
     * DB 上の最新の受注ステータス ID を行ロック付きで取得する.
     *
     * 他のリクエストが先に遷移させた後の状態を知るためのもの. ロックなしの SELECT は MySQL の REPEATABLE READ では
     * トランザクション開始時点のスナップショットを返すため、ロック付きで読む
     *
     * @param Order $order
     * @return int|null
     */
    public function fetchLatestOrderStatusId(Order $order): ?int
    {
        $statusId = $this->entityManager
            ->createQuery('SELECT IDENTITY(o.OrderStatus) FROM ' . Order::class . ' o WHERE o.id = :id')
            ->setParameter('id', $order->getId())
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getSingleScalarResult();

        return $statusId === null ? null : (int)$statusId;
    }

    /**
     * 注文完了メールを送信する.
     *
     * @param Order $order
     */
    public function sendOrderMail($order) {
        $this->mailService->sendOrderMail($order);
    }

    /**
     * 受注を受注番号で検索する.
     *
     * @param String $orderNo
     *
     * @return null|object
     */
    public function getOrderByNo($orderNo)
    {
        return $this->orderRepository->findOneBy([
            'order_no' => $orderNo,
        ]);
    }

    /**
     * 購入処理中の受注ステータスを返す
     *
     * @return object|null
     */
    public function getOrderStatusProcessing()
    {
        return $this->orderStatusRepository->find(OrderStatus::PROCESSING);
    }

    /**
     * 決済処理中の受注ステータスを返す
     *
     * @return object|null
     */
    public function getOrderStatusPending()
    {
        return $this->orderStatusRepository->find(OrderStatus::PENDING);
    }

    /**
     * 入金済みの受注ステータスを返す
     *
     * @return object|null
     */
    public function getOrderStatusPaid()
    {
        return $this->orderStatusRepository->find(OrderStatus::PAID);
    }

    /**
     * Create Code Object
     *
     * @param Order $order
     * @param string $frontUrl
     * @param array|null $metadata
     * @return array
     * @throws ApiException
     */
    public function createCodeObject($order, $frontUrl, $metadata = null)
    {
        /** @var CodeReq $codeReq */
        $codeReq = new CodeReq();
        $codeReq->setOrderNo($this->getOrderNo($order));
        // Order の金額は DECIMAL 由来の文字列（"1000.00" 等）で返ることがあるため整数に正規化する.
        // amount は整数しか受け付けないので、端数のある金額は切り捨てて請求せずエラーにする
        $paymentTotal = $order->getPaymentTotal();
        if ((int)$paymentTotal != $paymentTotal) {
            throw new \InvalidArgumentException('Payment total with a fractional part is not supported: ' . $paymentTotal);
        }
        $codeReq->setAmount((int)$paymentTotal);
        $codeReq->setCurrency($order->getCurrencyCode());
        $codeReq->setFrontUrl($frontUrl);
        $codeReq->setMetadata($metadata);

        /** @var CodeDto $codeDto */
        $codeDto = $this->codeApi->createCode($codeReq);
        $json = (string)$codeDto;
        return json_decode($json, true);
    }

    /**
     * Get Code Object
     *
     * @param string $codeId
     * @return array
     * @throws ApiException
     */
    public function getCodeObject($codeId)
    {
        /** @var CodeDto $codeDto */
        $codeDto = $this->codeApi->retrieveCode($codeId);
        $json = (string)$codeDto;
        return json_decode($json, true);
    }

    /**
     * Get Charge Object
     *
     * @param string $chargeId
     * @return array
     * @throws ApiException
     */
    public function getChargeObject($chargeId)
    {
        /** @var ChargeDto $chargeDto */
        $chargeDto = $this->chargeApi->retrieveCharge($chargeId);
        $json = (string)$chargeDto;
        return json_decode($json, true);
    }

    /**
     * @param Order $order
     * @return string
     */
    public function getOrderNo($order)
    {
        // Since the ECCUBE orderNo is an increment number, Create Charge will fail if a database reset occurs
        // Append the current time here to prevent duplicate order numbers
        return $order->getOrderNo() . '-' . date('His');
    }

    /**
     * @param string $orderNo
     * @return string
     */
    public function parseOrderNo($orderNo)
    {
        return explode('-', $orderNo)[0] ?? $orderNo;
    }

    /**
     * @return array
     */
    public function getPaymentMethods()
    {
        try {
            $url = $this->eccubeConfig->get('elepay.payment_methods_info_url');
            $headers = ['Content-Type' => 'application/json'];
            $request = new Request(
                'GET',
                $url,
                $headers
            );

            $response = $this->httpClient->send($request);
            $content = $response->getBody()->getContents();
            /**
             * $paymentMethodMap data structure
             * {
             *   "alipay": {
             *     "name": {
             *       "ja": "アリペイ",
             *       "en": "Alipay",
             *       "zh-CN": "支付宝",
             *       "zh-TW": "支付寶"
             *     },
             *     "image": {
             *       "short": "https://resource.elecdn.com/payment-methods/img/alipay.svg",
             *       "long": "https://resource.elecdn.com/payment-methods/img/alipay_long.svg"
             *     }
             *   },
             *   ...
             * }
             */
            $paymentMethodMap = json_decode($content, true);

            /** @var CodePaymentMethodResponse $codePaymentMethodResponse */
            $codePaymentMethodResponse = $this->codeSettingApi->listCodePaymentMethods();
            $json = (string)$codePaymentMethodResponse;
            /**
             * $availablePaymentMethods data structure
             * [
             *   {
             *     "paymentMethod": "alipay",
             *     "resources": [ "ios", "android", "web" ],
             *     "brand": [],
             *     "ua": "",
             *     "channelProperties": {}
             *   },
             *   ...
             * ]
             */
            $availablePaymentMethods = json_decode($json, true)['paymentMethods'];

            $paymentMethods = [];
            foreach ($availablePaymentMethods as $item) {
                $key = $item['paymentMethod'];
                $paymentMethodInfo = $paymentMethodMap[$key];

                if (
                    empty($key) ||
                    empty($paymentMethodInfo) ||
                    empty($item['resources']) ||
                    !in_array('web', $item['resources'])
                ) continue;

                if ($key === 'creditcard') {
                    foreach ($item['brand'] as $brand) {
                        $key = 'creditcard_' . $brand;
                        $paymentMethodInfo = $paymentMethodMap[$key];
                        $paymentMethods []= $this->getPaymentMethodInfo($key, $paymentMethodInfo, $item);
                    }
                } else {
                    $paymentMethods []= $this->getPaymentMethodInfo($key, $paymentMethodInfo, $item);
                }
            }
        } catch (Exception $e) {
            $paymentMethods = [];
        } catch (GuzzleException $e) {
            $paymentMethods = [];
        }

        return $paymentMethods;
    }

    private function getPaymentMethodInfo ($key, $paymentMethodInfo, $metaData)
    {
        return [
            'key' => $key,
            'name' => $paymentMethodInfo['name']['ja'],
            'image' => $paymentMethodInfo['image']['short'],
            'min' => null,
            'max' => null,
            'ua' => empty($metaData['ua']) ? '' : $metaData['ua']
        ];
    }

    public function addQuery($url, $params)
    {
        foreach ($params as $key => $value) {
            $url = $this->addQueryArg($url, $key, $value);
        }

        return $url;
    }

    public function addQueryArg($url, $key, $value)
    {
        $url = preg_replace('/(&)(#038;)?/', '$1', $url);
        preg_match('/(.*)([?&])' . $key . '=[^&]+?(&)(.*)/i', $url . '&', $match);
        if (!empty($match)) {
            $url = $match[1] . $match[2] . $key . '=' . $value . '&' . $match[4];
            $url = substr($url, 0, -1);
        } elseif (strstr($url, '?')) {
            if (preg_match('/(\?|&)$/', $url)) {
                $url .= $key . '=' . $value;
            } else {
                $url .= '&' . $key . '=' . $value;
            }
        } else {
            $url .= '?' . $key . '=' . $value;
        }
        return $url;
    }
}
