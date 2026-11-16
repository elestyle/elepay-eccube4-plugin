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
     * Connect timeout of outgoing HTTP requests (seconds)
     */
    private const HTTP_CONNECT_TIMEOUT = 5;

    /**
     * Response timeout of outgoing HTTP requests (seconds)
     */
    private const HTTP_TIMEOUT = 20;

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
        // Limit the connect and response time, so that waiting on an external service does not hang the checkout or admin pages
        $this->httpClient = new Client([
            'connect_timeout' => self::HTTP_CONNECT_TIMEOUT,
            'timeout' => self::HTTP_TIMEOUT,
        ]);

        $secretKey = $this->config->getSecretKey();
        $elepayApiHost = $this->eccubeConfig->get('elepay.api_host');

        $config = Configuration::getDefaultConfiguration()
            ->setUsername($secretKey)
            ->setPassword('')
            ->setHost($elepayApiHost);

        $this->codeApi = new CodeApi($this->httpClient, $config);
        $this->chargeApi = new ChargeApi($this->httpClient, $config);
        $this->codeSettingApi = new CodeSettingApi($this->httpClient, $config);
    }

    /**
     * Gets the order of the current cart whose payment is in progress.
     *
     * @return null|object
     */
    public function getCartOrder()
    {
        $preOrderId = $this->cartService->getPreOrderId();
        // Without a cart, do not pick up an order with pre_order_id IS NULL (e.g. an order registered in the admin)
        if (empty($preOrderId)) {
            return null;
        }

        return $this->orderRepository->findOneBy([
            'pre_order_id' => $preOrderId,
        ]);
    }

    /**
     * Empties the cart of the current session
     */
    public function cartClear()
    {
        $this->cartService->clear();
    }

    /**
     * Deletes the cart with the same pre_order_id as the order. Does nothing if it is already deleted
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
     * Changes the order status to $toStatusId, only if the current status is one of $fromStatusIds.
     *
     * A conditional UPDATE narrows the status transition in the DB down to one request, so that when a payment capture and a
     * cancel happen at once, only one of them runs the follow-up. A later UPDATE on the same row waits on the row lock until
     * the earlier transaction ends, then matches no row because it has already moved on, and returns false.
     * Call it inside the request-wide transaction: if the follow-up fails, this UPDATE has to be rolled back with it.
     * It does not update the caller's entity, so the caller has to bring the entity's status in line
     *
     * @param Order $order
     * @param int[] $fromStatusIds
     * @param int $toStatusId
     * @return bool true if this call made the transition
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
     * Gets the latest order status ID in the DB, with a row lock.
     *
     * Used to learn the status after another request has made a transition first. Under MySQL REPEATABLE READ a SELECT
     * without a lock returns the snapshot taken at the start of the transaction, so read with a lock
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
     * Sends the order complete mail.
     *
     * @param Order $order
     */
    public function sendOrderMail($order) {
        $this->mailService->sendOrderMail($order);
    }

    /**
     * Finds an order by its order number.
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
     * Returns the PROCESSING order status
     *
     * @return object|null
     */
    public function getOrderStatusProcessing()
    {
        return $this->orderStatusRepository->find(OrderStatus::PROCESSING);
    }

    /**
     * Returns the PENDING order status
     *
     * @return object|null
     */
    public function getOrderStatusPending()
    {
        return $this->orderStatusRepository->find(OrderStatus::PENDING);
    }

    /**
     * Returns the PAID order status
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
        // Order amounts can come back as strings from DECIMAL columns (e.g. "1000.00"), so normalize them to integers.
        // amount accepts integers only, so a fractional amount is an error rather than being truncated and charged
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
            $availablePaymentMethods = json_decode($json, true)['paymentMethods'] ?? null;
            if (!is_array($paymentMethodMap) || !is_array($availablePaymentMethods)) {
                return [];
            }

            $paymentMethods = [];
            foreach ($availablePaymentMethods as $item) {
                $key = $item['paymentMethod'] ?? null;
                $paymentMethodInfo = $paymentMethodMap[$key] ?? null;

                if (
                    empty($key) ||
                    empty($paymentMethodInfo) ||
                    empty($item['resources']) ||
                    !in_array('web', $item['resources'])
                ) continue;

                if ($key === 'creditcard') {
                    foreach (($item['brand'] ?? []) as $brand) {
                        $key = 'creditcard_' . $brand;
                        $paymentMethodInfo = $paymentMethodMap[$key] ?? null;
                        if (empty($paymentMethodInfo)) continue;
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
        $image = $paymentMethodInfo['image']['short'] ?? null;

        return [
            'key' => $key,
            'name' => (string)($paymentMethodInfo['name']['ja'] ?? $key),
            // These come from external JSON, so never show a non-https URL (javascript: etc.)
            'image' => $this->isHttpsUrl($image) ? $image : null,
            'min' => null,
            'max' => null,
            'ua' => empty($metaData['ua']) ? '' : $metaData['ua']
        ];
    }

    /**
     * Whether the URL is https and has a host
     *
     * @param mixed $url
     * @return bool
     */
    public function isHttpsUrl($url): bool
    {
        if (!is_string($url) || $url === '') {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && !empty($parts['host']);
    }

    /**
     * Adds query parameters to a URL. A parameter with the same name is replaced; keys and values are URL-encoded.
     * The existing query is kept as is, without re-parsing it
     *
     * @param string $url
     * @param array $params
     * @return string
     */
    public function addQuery($url, $params)
    {
        $fragment = '';
        $hashPos = strpos($url, '#');
        if ($hashPos !== false) {
            $fragment = substr($url, $hashPos);
            $url = substr($url, 0, $hashPos);
        }

        $query = '';
        $queryPos = strpos($url, '?');
        if ($queryPos !== false) {
            $query = substr($url, $queryPos + 1);
            $url = substr($url, 0, $queryPos);
        }

        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') continue;
            $name = urldecode(explode('=', $pair, 2)[0]);
            if (array_key_exists($name, $params)) continue;
            $pairs[] = $pair;
        }
        foreach ($params as $key => $value) {
            $pairs[] = rawurlencode((string)$key) . '=' . rawurlencode((string)$value);
        }

        return $url . '?' . implode('&', $pairs) . $fragment;
    }
}
