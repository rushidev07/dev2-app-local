<?php
declare(strict_types=1);

namespace FalcoSense\Search\Model\Plp;

/**
 * Everything needed to ask the platform for one view of a product listing, and
 * nothing Magento-internal. Built once per request by PageContext, then handed
 * straight through PlpDataProviderInterface. Immutable — a filter/sort/page
 * change on the frontend produces a *new* query, it never mutates one.
 *
 * Ported from Ahy_SmartSearchLuma's Model/Plp/PlpQuery.php (namespace swap
 * only) — this value object carries no platform-specific wire-format
 * assumptions, so it's directly reusable as-is.
 */
final class PlpQuery
{
    public const CONTEXT_CATEGORY = 'category';
    public const CONTEXT_SEARCH   = 'search';

    /**
     * @param array<string, string[]> $filters attributeCode => selected display values
     */
    public function __construct(
        public readonly string $contextType,
        public readonly int $storeId,
        public readonly int $platformStoreId,
        public readonly int $page,
        public readonly int $perPage,
        public readonly string $sort = 'relevance',
        public readonly array $filters = [],
        public readonly ?float $priceMin = null,
        public readonly ?float $priceMax = null,
        public readonly ?int $categoryId = null,
        public readonly ?string $categoryName = null,
        public readonly ?string $searchQuery = null,
    ) {
    }

    public function isCategory(): bool
    {
        return $this->contextType === self::CONTEXT_CATEGORY;
    }

    public function isSearch(): bool
    {
        return $this->contextType === self::CONTEXT_SEARCH;
    }

    /**
     * True when the shopper is looking at the plain, canonical view of a
     * listing — no filters, no custom sort, first page. Only this view is
     * rendered server-side into the initial (cacheable, crawlable) response;
     * everything else is a fragment fetch driven by Alpine.
     */
    public function isCanonicalView(): bool
    {
        return $this->page === 1
            && $this->filters === []
            && $this->priceMin === null
            && $this->priceMax === null
            && in_array($this->sort, ['', 'relevance', 'position'], true);
    }

    public function cacheKey(): string
    {
        $parts = [
            'v1',
            $this->contextType,
            'store' => $this->storeId,
            'cat'   => $this->categoryId ?? '',
            'q'     => $this->searchQuery !== null ? mb_strtolower(trim($this->searchQuery)) : '',
            'p'     => $this->page,
            'pp'    => $this->perPage,
            'sort'  => $this->sort,
            'pmin'  => $this->priceMin ?? '',
            'pmax'  => $this->priceMax ?? '',
            'f'     => $this->normalizedFilters(),
        ];

        return 'plp_' . hash('sha256', json_encode($parts, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, string[]>
     */
    private function normalizedFilters(): array
    {
        $out = [];
        foreach ($this->filters as $key => $values) {
            $values = array_values(array_unique(array_map('strval', (array) $values)));
            sort($values);
            if ($values !== []) {
                $out[(string) $key] = $values;
            }
        }
        ksort($out);

        return $out;
    }
}
