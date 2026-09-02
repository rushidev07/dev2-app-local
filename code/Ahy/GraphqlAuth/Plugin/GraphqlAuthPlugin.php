<?php
declare(strict_types=1);

namespace Ahy\GraphqlAuth\Plugin;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;

class GraphqlAuthPlugin
{
    private SerializerInterface $jsonSerializer;
    private CacheInterface $cache;
    private LoggerInterface $logger;

    private const RATE_LIMIT = 60; // requests
    private const RATE_WINDOW = 60; // seconds
    private const MAX_PAGE_SIZE = 50;

    /** Sensitive fields requiring token */
    private array $sensitiveFields = [
        'customer',
        'customerCart',
        'customerOrders',
        'customerWishlist',
        'cart',
        'addProductsToCart',
        'removeProductsFromCart',
        'applyCoupon',
        'placeOrder',
        'cancelOrder'
    ];

    public function __construct(
        SerializerInterface $jsonSerializer,
        CacheInterface $cache,
        LoggerInterface $loggerInterface
    ) {
        $this->jsonSerializer = $jsonSerializer;
        $this->cache = $cache;
        $this->logger = $loggerInterface;
    }

    public function beforeDispatch(
        \Magento\GraphQl\Controller\GraphQl $subject,
        RequestInterface $request
    ) { 
        $this->logger->info('GraphQL Request Received', [
            'method' => $request->getMethod(),
            'uri' => $request->getRequestUri(),
            'ip' => $request->getServer('REMOTE_ADDR') ?? 'unknown'
        ]);
        // Apply rate limit
        $this->applyRateLimit($request);
      
        $query = '';

        if ($request->isPost()) {
            $content = $request->getContent();

            if (!empty($content)) {
                $data = $this->jsonSerializer->unserialize($content);
                $query = $data['query'] ?? '';
            }

        } elseif ($request->isGet()) {
            $params = $request->getParams();
            $query = $params['query'] ?? '';
        }

        if (!$query) {
            return;
        }

        // Block GraphQL introspection
        if (stripos($query, '__schema') !== false || stripos($query, '__type') !== false) {
            throw new GraphQlAuthorizationException(
                __('GraphQL introspection is disabled.')
            );
        }

        // // Enforce pageSize limit
        // $this->enforcePageSizeLimit($query);

        // Protect sensitive fields
        foreach ($this->sensitiveFields as $field) {
            if (stripos($query, $field) !== false) {
                $this->checkBearerToken($request);
                break;
            }
        }
    }

    private function checkBearerToken(RequestInterface $request): void
    {
        $authHeader = $request->getHeader('Authorization') ?: '';

        if (!preg_match('/Bearer\s+.+/', $authHeader)) {
            throw new GraphQlAuthorizationException(
                __('Unauthorized: Bearer token required.')
            );
        }
    }

    // private function enforcePageSizeLimit(string $query): void
    // {
    //     if (preg_match('/pageSize\s*:\s*(\d+)/i', $query, $matches)) {

    //         $pageSize = (int)$matches[1];

    //         if ($pageSize > self::MAX_PAGE_SIZE) {
    //             throw new GraphQlAuthorizationException(
    //                 __('Maximum pageSize allowed is %1', self::MAX_PAGE_SIZE)
    //             );
    //         }
    //     }
    // }
    private function applyRateLimit(RequestInterface $request): void
    {
        $ip = $this->getClientIp($request);

        $cacheKey = 'gql_rate_' . md5($ip);

        $data = $this->cache->load($cacheKey);
        $count = $data ? (int)$data : 0;
        $count++;

        $time = date('Y-m-d H:i:s');

        $this->logger->info('GraphQL Rate Limit Check', [
            'ip' => $ip,
            'count' => $count,
            'time' => $time
        ]);

        if ($count > self::RATE_LIMIT) {
            $this->logger->warning('GraphQL Rate Limit BLOCKED', [
                'ip' => $ip,
                'count' => $count,
                'time' => $time
            ]);

            throw new GraphQlAuthorizationException(
                __('Too many GraphQL requests. Please slow down.')
            );
        }

        $this->cache->save(
            (string)$count,
            $cacheKey,
            ['GRAPHQL_RATE_LIMIT'],
            self::RATE_WINDOW
        );
    }

    private function getClientIp(RequestInterface $request): string
    {
        $ip = $request->getServer('HTTP_X_FORWARDED_FOR') ?: $request->getServer('REMOTE_ADDR') ?: 'unknown';
        return explode(',', $ip)[0]; // In case of multiple IPs, take the first one
    }
}