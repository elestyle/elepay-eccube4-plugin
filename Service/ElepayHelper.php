<?php

namespace Plugin\Elepay\Service;

require_once(__DIR__ . '/../Resource/vendor/autoload.php');

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
use Plugin\Elepay\Entity\Config;
use Plugin\Elepay\Repository\ConfigRepository;

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
        // $orderStatus = $this->orderStatusRepository->find(OrderStatus::PENDING);

        return $this->orderRepository->findOneBy([
            'pre_order_id' => $preOrderId,
            // 'OrderStatus' => $orderStatus,
        ]);
    }

    /**
     * Get Cart Key
     *
     * @return string
     */
    public function getCartKey()
    {
        $cart = $this->cartService->getCart();
        if (!empty($cart)) {
            return $cart->getCartKey();
        }
        return null;
    }

    /**
     * Cart Clear
     *
     * @param $cartKey
     */
    public function cartClear($cartKey = null)
    {
        if (!empty($cartKey)) {
            $cart = $this->cartRepository->findOneBy(['cart_key' => $cartKey]);
            $this->entityManager->remove($cart);
            $this->entityManager->flush();
        } else {
            $this->cartService->clear();
        }
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
     * 受注をIDで検索する.
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
     * 返回 PROCESSING 订单状态对象
     *
     * @return object|null
     */
    public function getOrderStatusProcessing()
    {
        return $this->orderStatusRepository->find(OrderStatus::PROCESSING);
    }

    /**
     * 返回 PENDING 订单状态对象
     *
     * @return object|null
     */
    public function getOrderStatusPending()
    {
        return $this->orderStatusRepository->find(OrderStatus::PENDING);
    }

    /**
     * 返回 PAID 订单状态对象
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
     * @param array|null $extra
     * @return array
     * @throws ApiException
     */
    public function createCodeObject($order, $frontUrl, $extra = null)
    {
        /** @var CodeReq $codeReq */
        $codeReq = new CodeReq();
        $codeReq->setOrderNo($this->getOrderNo($order));
        $codeReq->setAmount($order->getPaymentTotal());
        $codeReq->setCurrency($order->getCurrencyCode());
        $codeReq->setFrontUrl($frontUrl);
        $codeReq->setExtra($extra);

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
        // Add preOrderId here to prevent duplicate order numbers
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
