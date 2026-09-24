<?php
declare(strict_types=1);

namespace FalcoSense\Search\Model\Plp;

use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Structured data for a FalcoSense listing.
 *
 * Built from the SAME PlpResult that produced the visible grid, so the price and
 * availability in the JSON-LD can never disagree with what a shopper sees. That
 * parity is the entire reason this exists instead of reusing Magento's own
 * catalog structured data, which reads from the database — a different source
 * than the platform API our grid renders from, and therefore free to drift.
 *
 * WHAT IT EMITS
 * -------------
 * ItemList, with a Product + Offer per row. That is what answer engines and rich
 * results use to understand a listing page.
 *
 * WHAT IT DELIBERATELY DOES NOT EMIT
 * ----------------------------------
 * BreadcrumbList. The upstream version in Ahy_SmartSearchLuma emits it, but
 * Everest already runs OuterEdge_StructuredData, which owns breadcrumb markup
 * (its theme override targets the breadcrumbs block). Two BreadcrumbList blocks
 * on one page is worse than none — it gives crawlers conflicting trails. If a
 * storefront has no breadcrumb schema of its own, that is the place to add it.
 *
 * aggregateRating is also absent: FalcoSense's PlpItem carries no rating fields.
 * A host that has ratings (Everest gets them from Yotpo, client-side) should add
 * them through its own markup rather than have this guess.
 *
 * WHERE IT RUNS
 * -------------
 * Canonical views only. Filtered and paged URLs are marked NOINDEX,FOLLOW by
 * PlpSeoObserver, so structured data there would describe a page we have asked
 * not to be indexed.
 */
class JsonLdBuilder
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return string a <script type="application/ld+json"> block, or '' when
     *                there is nothing worth emitting
     */
    public function build(PlpResult $result, PlpQuery $query): string
    {
        if (!$result->isUsable() || $result->items === []) {
            return '';
        }

        $json = json_encode(
            $this->itemList($result, $query),
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        );

        if ($json === false) {
            $this->logger->warning('[FalcoSense][PLP] JSON-LD encode failed: ' . json_last_error_msg());
            return '';
        }

        return '<script type="application/ld+json">' . $json . '</script>';
    }

    private function itemList(PlpResult $result, PlpQuery $query): array
    {
        $currency = $this->currencyCode($query->storeId);
        $baseUrl  = $this->baseUrl($query->storeId);

        /*
         * `position` is the item's place in the WHOLE result set, not just this
         * page — so page 2 starts at 51, not 1. Restarting the count per page
         * would tell a crawler every page contains items 1..50.
         */
        $offset = ($result->page - 1) * max(1, $result->perPage);

        $elements = [];
        foreach ($result->items as $i => $item) {
            if (!$item instanceof PlpItem) {
                continue;
            }

            $url = $item->urlKey !== '' ? $baseUrl . $item->urlKey . '.html' : $baseUrl;

            $product = [
                '@type' => 'Product',
                'name'  => $item->name,
                'url'   => $url,
            ];

            if ($item->imageUrl !== '') {
                $product['image'] = $item->imageUrl;
            }
            if ($item->sku !== '') {
                $product['sku'] = $item->sku;
            }
            if ($item->brand !== null && $item->brand !== '') {
                $product['brand'] = ['@type' => 'Brand', 'name' => $item->brand];
            }

            $price = $item->effectivePrice();
            if ($price !== null) {
                $product['offers'] = [
                    '@type'         => 'Offer',
                    'price'         => number_format($price, 2, '.', ''),
                    'priceCurrency' => $currency,
                    'availability'  => $item->inStock
                        ? 'https://schema.org/InStock'
                        : 'https://schema.org/OutOfStock',
                    'url'           => $url,
                ];
            }

            $elements[] = [
                '@type'    => 'ListItem',
                'position' => $offset + $i + 1,
                'item'     => $product,
            ];
        }

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'numberOfItems'   => $result->total,
            'itemListElement' => $elements,
        ];
    }

    private function currencyCode(int $storeId): string
    {
        try {
            return (string) $this->storeManager->getStore($storeId)->getCurrentCurrencyCode();
        } catch (\Throwable $e) {
            $this->logger->warning('[FalcoSense][PLP] Currency lookup failed: ' . $e->getMessage());
            return 'USD';
        }
    }

    private function baseUrl(int $storeId): string
    {
        try {
            return (string) $this->storeManager->getStore($storeId)->getBaseUrl();
        } catch (\Throwable $e) {
            $this->logger->warning('[FalcoSense][PLP] Base URL lookup failed: ' . $e->getMessage());
            return '/';
        }
    }
}
