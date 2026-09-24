<?php
declare(strict_types=1);

namespace FalcoSense\Search\Service\Plp;

use FalcoSense\Search\Api\PlpDataProviderInterface;
use FalcoSense\Search\Helper\Data;
use FalcoSense\Search\Model\Plp\PlpFacet;
use FalcoSense\Search\Model\Plp\PlpItem;
use FalcoSense\Search\Model\Plp\PlpQuery;
use FalcoSense\Search\Model\Plp\PlpResult;
use FalcoSense\Search\Service\SearchTokenService;
use Psr\Log\LoggerInterface;

/**
 * Server-side adapter to the FalcoSense platform's /api/v1/products endpoint
 * — this is what makes real SSR possible: today, this exact API call only
 * ever happens from the browser (search/results.phtml's and
 * category/results.phtml's own Alpine `fetch()`). This class makes the
 * identical call from PHP, before the page ever reaches the browser, using
 * the same params so the server and client code paths stay in sync by
 * construction rather than by convention.
 *
 * Search params mirror search/results.phtml's fetch() (q, page, per_page,
 * include_variants — platform_store_id deliberately omitted, same reasoning
 * as the JS: getPlatformStoreId()'s position-based calculation is wrong for
 * single-store-view sites). Search stays scoped to the canonical view only
 * (see PageContext::buildSearchQuery).
 *
 * Category params mirror category/results.phtml's fetch() (category,
 * category_ids, page, per_page, sort — platform_store_id omitted for the
 * same reason). Category renders for every page/sort, not just canonical
 * (see PageContext::buildCategoryQuery) — filters/price aren't included
 * since category's own client never encodes them in the URL either.
 */
class FalcoSensePlpProvider implements PlpDataProviderInterface
{
    private const FALLBACK_IMAGE = '/media/.thumbswysiwyg/everest-logo_2_.png';

    public function __construct(
        private readonly Data $helper,
        private readonly SearchTokenService $tokenService,
        private readonly PlatformHttpClient $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Request-scoped memo, keyed by PlpQuery::cacheKey().
     *
     * More than one block can legitimately need the same listing in a single
     * render — the grid renders it, and the structured-data block describes it.
     * Without this, each one issues its own platform HTTP call for identical
     * data, doubling the latency of every listing page.
     *
     * Deliberately in-memory and per-request: the result is already bounded by
     * the response lifetime, and a persistent cache here would need invalidation
     * rules that belong to the platform, not to us.
     *
     * @var array<string, PlpResult>
     */
    private array $memo = [];

    public function fetch(PlpQuery $query): PlpResult
    {
        $key = $query->cacheKey();
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $result = $query->isCategory()
            ? $this->fetchCategory($query)
            : $this->fetchSearch($query);

        return $this->memo[$key] = $result;
    }

    private function fetchSearch(PlpQuery $query): PlpResult
    {
        if (!$query->isSearch() || $query->searchQuery === null || trim($query->searchQuery) === '') {
            return PlpResult::unavailable();
        }

        $url = $this->helper->buildPlatformUrl('/api/v1/products', $query->storeId);
        if ($url === '') {
            $this->logger->warning('[SmartSearch][PLP] Platform endpoint not configured — cannot render SSR grid.');
            return PlpResult::unavailable();
        }

        $token = $this->tokenService->getToken($query->storeId);
        if ($token === '') {
            $this->logger->warning('[SmartSearch][PLP] No search token available — cannot render SSR grid.');
            return PlpResult::unavailable();
        }

        // TEMPORARY benchmark instrumentation — isolates the module's own cost
        // (platform round-trip + response mapping) from Magento's own bootstrap
        // overhead, which every page pays regardless of FalcoSense. Remove once
        // the module's contribution is confirmed.
        $benchStart = microtime(true);
        $this->logTimeSinceRequestStart($benchStart, 'search "' . $query->searchQuery . '"');

        try {
            $decoded = $this->http->getJson(
                $url,
                [
                    'search_token'     => $token,
                    'q'                => $query->searchQuery,
                    'page'             => $query->page,
                    'per_page'         => $query->perPage,
                    'include_variants' => '1',
                ],
                [],
                $this->helper->getPlpPlatformTimeoutMs($query->storeId)
            );
            $this->logger->info(sprintf(
                '[SmartSearch][BENCH] Platform round-trip for "%s": %dms',
                $query->searchQuery,
                (int) round((microtime(true) - $benchStart) * 1000)
            ));
        } catch (PlatformRequestException $e) {
            $this->logger->warning('[SmartSearch][PLP] ' . $e->getMessage());
            return PlpResult::unavailable();
        }

        $result = $this->mapResponse($decoded, $query);
        $this->logger->info(sprintf(
            '[SmartSearch][BENCH] Total provider time (round-trip + mapping) for "%s": %dms',
            $query->searchQuery,
            (int) round((microtime(true) - $benchStart) * 1000)
        ));

        return $result;
    }

    private function fetchCategory(PlpQuery $query): PlpResult
    {
        $hasName = $query->categoryName !== null && trim($query->categoryName) !== '';
        if (!$query->isCategory() || (!$hasName && !$query->categoryId)) {
            return PlpResult::unavailable();
        }

        $url = $this->helper->buildPlatformUrl('/api/v1/products', $query->storeId);
        if ($url === '') {
            $this->logger->warning('[SmartSearch][PLP] Platform endpoint not configured — cannot render SSR grid.');
            return PlpResult::unavailable();
        }

        $token = $this->tokenService->getToken($query->storeId);
        if ($token === '') {
            $this->logger->warning('[SmartSearch][PLP] No search token available — cannot render SSR grid.');
            return PlpResult::unavailable();
        }

        $params = [
            'search_token' => $token,
            'category'     => (string) $query->categoryName,
            'page'         => $query->page,
            'per_page'     => $query->perPage,
        ];
        // Two categories can share the same name, so category_ids scopes the
        // match to this exact category — same reasoning as the client-side
        // fetch() in category/results.phtml.
        if ($query->categoryId) {
            $params['category_ids'] = $query->categoryId;
        }
        if ($query->sort !== 'relevance' && $query->sort !== '') {
            $params['sort'] = $query->sort;
        }

        $benchStart = microtime(true);
        $this->logTimeSinceRequestStart($benchStart, 'category "' . $query->categoryName . '" (p' . $query->page . ')');

        try {
            $decoded = $this->http->getJson(
                $url,
                $params,
                [],
                $this->helper->getPlpPlatformTimeoutMs($query->storeId)
            );
            $this->logger->info(sprintf(
                '[SmartSearch][BENCH] Platform round-trip for category "%s" (p%d): %dms',
                $query->categoryName,
                $query->page,
                (int) round((microtime(true) - $benchStart) * 1000)
            ));
        } catch (PlatformRequestException $e) {
            $this->logger->warning('[SmartSearch][PLP] ' . $e->getMessage());
            return PlpResult::unavailable();
        }

        $result = $this->mapResponse($decoded, $query);
        $this->logger->info(sprintf(
            '[SmartSearch][BENCH] Total provider time (round-trip + mapping) for category "%s" (p%d): %dms',
            $query->categoryName,
            $query->page,
            (int) round((microtime(true) - $benchStart) * 1000)
        ));

        return $result;
    }

    /**
     * TEMPORARY benchmark instrumentation — logs how much time Magento itself
     * spent (bootstrap, routing, layout generation, any earlier blocks)
     * BEFORE this SSR call even began, using PHP's own request-start
     * timestamp ($_SERVER['REQUEST_TIME_FLOAT'], set before Magento boots)
     * as the origin. This is what lets "the rest of the time is the website,
     * not FalcoSense" be read directly out of one log file for one request,
     * instead of eyeballing a server log line against a separate DevTools
     * screenshot and matching them by closest timestamp. Doesn't capture
     * time spent AFTER this call returns (the rest of page rendering,
     * sending the response) — only the "before" portion. Remove alongside
     * the other BENCH instrumentation once no longer needed.
     */
    private function logTimeSinceRequestStart(float $now, string $label): void
    {
        $requestStart = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        if (!is_numeric($requestStart)) {
            return;
        }

        $this->logger->info(sprintf(
            '[SmartSearch][BENCH] Magento time BEFORE reaching FalcoSense (request start -> platform call) for %s: %dms',
            $label,
            (int) round(($now - (float) $requestStart) * 1000)
        ));
    }

    private function mapResponse(array $decoded, PlpQuery $query): PlpResult
    {
        $items = array_values(array_filter(array_map(
            fn (array $row) => $this->mapItem($row),
            array_values((array) ($decoded['data'] ?? []))
        )));

        $facets = array_map(
            fn (array $f) => $this->mapFacet($f),
            array_values((array) ($decoded['facets'] ?? []))
        );

        $pagination = (array) ($decoded['pagination'] ?? []);

        return new PlpResult(
            items: $items,
            facets: $facets,
            total: (int) ($pagination['total'] ?? count($items)),
            page: $query->page,
            perPage: (int) ($pagination['per_page'] ?? $query->perPage),
        );
    }

    private function mapItem(array $row): ?PlpItem
    {
        $productId = (int) ($row['product_id'] ?? 0);
        if ($productId <= 0) {
            return null;
        }

        return new PlpItem(
            productId: $productId,
            sku: (string) ($row['sku'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            urlKey: (string) ($row['url_key'] ?? ''),
            imageUrl: $this->resolveImageUrl($row['image'] ?? null),
            price: isset($row['price']) && $row['price'] !== null ? (float) $row['price'] : null,
            specialPrice: isset($row['special_price']) && $row['special_price'] !== null ? (float) $row['special_price'] : null,
            brand: isset($row['brand']) && $row['brand'] !== '' ? (string) $row['brand'] : null,
            type: (string) ($row['type'] ?? 'simple'),
            inStock: (bool) ($row['in_stock'] ?? true),
            variants: is_array($row['variants'] ?? null) ? $row['variants'] : [],
        );
    }

    private function mapFacet(array $f): PlpFacet
    {
        $options = [];
        foreach ((array) ($f['options'] ?? []) as $opt) {
            $value = (string) ($opt['value'] ?? '');
            if ($value === '') {
                continue;
            }
            $options[] = ['value' => $value, 'count' => (int) ($opt['count'] ?? 0)];
        }

        return new PlpFacet(
            key: (string) ($f['key'] ?? ''),
            label: (string) ($f['label'] ?? $f['key'] ?? ''),
            options: $options,
            min: isset($f['min']) ? (float) $f['min'] : null,
            max: isset($f['max']) ? (float) $f['max'] : null,
        );
    }

    /**
     * Same resolution algorithm as search/results.phtml's client-side
     * imgUrl() (lines 741-757) — a fallback for a missing/placeholder image,
     * pass-through for an already-CDN-resolved falcosense/800x800 URL, and
     * otherwise reconstructing the /media/falcosense/800x800/{c1}/{c2}/{file}
     * sharded path from either an absolute Magento media URL or a bare
     * relative filename. Kept in sync deliberately, not shared, since a
     * shared PHP/JS helper isn't practical here — this is the SSR-side copy.
     */
    private function resolveImageUrl(?string $image): string
    {
        if (!$image || str_contains($image, 'no_selection')) {
            return self::FALLBACK_IMAGE;
        }
        if (str_contains($image, 'falcosense/800x800')) {
            return $image;
        }

        $path = $image;
        if (str_starts_with($image, 'http')) {
            if (!preg_match('#/catalog/product/(.+)$#', $image, $m)) {
                return $image;
            }
            $path = $m[1];
        } else {
            $path = ltrim($path, '/');
        }

        if ($path === '') {
            return self::FALLBACK_IMAGE;
        }

        $filename = basename($path);
        if ($filename === '') {
            return self::FALLBACK_IMAGE;
        }

        $c1 = $filename[0];
        $c2 = $filename[1] ?? $c1;

        return "/media/falcosense/800x800/{$c1}/{$c2}/{$filename}";
    }
}
