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
 * Server-side adapter to the FalcoSense platform's /api/v1/products endpoint,
 * for the search results page's canonical (page 1, no filters, default sort)
 * view only — this is what makes real SSR possible: today, this exact API
 * call only ever happens from the browser (search/results.phtml's Alpine
 * `fetch()`). This class makes the identical call from PHP, before the page
 * ever reaches the browser, using the same params so the two code paths stay
 * in sync by construction rather than by convention.
 *
 * Params mirror search/results.phtml's fetch() (q, page, per_page,
 * include_variants, geo_state, bypass_spell — platform_store_id
 * deliberately omitted, same reasoning as the JS: getPlatformStoreId()'s
 * position-based calculation is wrong for single-store-view sites).
 * Category support (category/category_ids/sort params) is intentionally not
 * built here yet — out of scope for this pass, search only.
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

    public function fetch(PlpQuery $query): PlpResult
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
        } catch (PlatformRequestException $e) {
            $this->logger->warning('[SmartSearch][PLP] ' . $e->getMessage());
            return PlpResult::unavailable();
        }

        return $this->mapResponse($decoded, $query);
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
