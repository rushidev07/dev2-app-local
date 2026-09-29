<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Block\Product\View;

use Ahy\PDPRevamp\Model\Product\RelatedCarouselDataProvider;
use Ahy\PDPRevamp\Model\ResourceModel\PurchaseAffinity;
use Ahy\PDPRevamp\Setup\Patch\Data\CreateManualCarouselLinkTypes;
use Hyva\Theme\Model\ViewModelRegistry;
use Hyva\Theme\ViewModel\CurrentProduct;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Webkul\Marketplace\Helper\Data as MarketplaceHelper;

/**
 * PDP "Customers Also Bought" carousel. Sources candidates from
 * PurchaseAffinity - a from-scratch port of Amasty Mostviewed's own
 * "bought together" SQL (see that class's docblock for exactly what was
 * kept and what was deliberately dropped: the 30-day window), rather than
 * calling into Amasty at request time - and falls back to the
 * admin-curated manual link grid when there's no purchase history yet.
 *
 * Badge priority: the admin-managed pdp_cab_badge product attribute (see
 * Setup\Patch\Data\CreatePdpCabBadgeAttribute) is multiselect - when an admin
 * has selected one or more values on the product (Stores > Attributes >
 * Product > pdp_cab_badge), they always win and override everything below,
 * and a product can show more than one at once (e.g. both "Trending" and
 * "Essential"). When none are selected, the original automatic badges apply,
 * in this priority order: Best Seller (Magento's own sales bestsellers
 * report) > Top Rated (rating >= 90%) > Same Seller (same marketplace seller
 * as the product currently being viewed) > Under $X (the 2nd-cheapest item
 * in this carousel).
 *
 * Bestseller/seller-id/review-summary batching and the brand-fallback
 * label are shared with AdventureSeekersAlsoViewed via
 * RelatedCarouselDataProvider - see that class for the reasoning.
 */
class CustomersAlsoBought extends Template
{
    public const GROUP_NAME = 'Customers Also Bought';

    /** Matches the old Amasty group's max_products setting - the display cap. */
    private const MAX_PRODUCTS = 10;

    /**
     * How many co-bought candidates to pull before the isSaleable() check
     * below runs, so that check has room to reject a few without
     * starving the carousel down to a handful of items.
     */
    private const CANDIDATE_POOL_SIZE = 30;

    /**
     * Maps a pdp_cab_badge option label to the badge's background color.
     * A label with no entry here (e.g. a new option an admin just added
     * via Manage Options) simply renders no badge until a color is added.
     */
    private const CAB_BADGE_COLORS = [
        '#1 Paired Item' => '#E75C26',
        'Trending' => '#16a34a',
        'Essential' => '#0d2f47',
    ];

    /**
     * Deliberately the same config path Frequently Bought Together uses
     * (Stores > Configuration > Ahy > PDP Frequently Bought Together >
     * Never Suggest These SKUs), not a separate CAB-specific list - one
     * admin-managed list of promotional/freebie SKUs (e.g. "FREE Everest
     * Decal", confirmed via this store's own order data to otherwise
     * dominate co-purchase counts here exactly like it did for FBT)
     * covers both carousels instead of needing to be maintained twice.
     */
    private const XML_PATH_EXCLUDED_SKUS = 'pdprevamp_fbt/general/excluded_skus';

    private RelatedCarouselDataProvider $dataProvider;
    private ViewModelRegistry $viewModelRegistry;
    private ImageHelper $imageHelper;
    private MarketplaceHelper $marketplaceHelper;
    private EavConfig $eavConfig;
    private PurchaseAffinity $purchaseAffinity;
    private StoreManagerInterface $storeManager;
    private ResourceConnection $resourceConnection;
    private LoggerInterface $logger;

    /** @var array|null */
    private $items;

    private ?string $currentSellerName = null;
    private bool $currentSellerNameResolved = false;

    /** @var array<string, string>|null */
    private ?array $cabBadgeOptionsById = null;

    /**
     * Which tier actually produced getProducts()'s result, set as a side
     * effect of that call - getSubtitle() reads it to vary its copy.
     * One of: 'manual', 'purchase', 'seller', 'department', null (nothing
     * has run yet).
     */
    private ?string $sourceTier = null;

    public function __construct(
        Context $context,
        RelatedCarouselDataProvider $dataProvider,
        ViewModelRegistry $viewModelRegistry,
        ImageHelper $imageHelper,
        MarketplaceHelper $marketplaceHelper,
        EavConfig $eavConfig,
        PurchaseAffinity $purchaseAffinity,
        StoreManagerInterface $storeManager,
        ResourceConnection $resourceConnection,
        LoggerInterface $logger,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->dataProvider = $dataProvider;
        $this->viewModelRegistry = $viewModelRegistry;
        $this->imageHelper = $imageHelper;
        $this->marketplaceHelper = $marketplaceHelper;
        $this->eavConfig = $eavConfig;
        $this->purchaseAffinity = $purchaseAffinity;
        $this->storeManager = $storeManager;
        $this->resourceConnection = $resourceConnection;
        $this->logger = $logger;
    }

    /**
     * Configured SKUs resolved to ids - exact match only, same reasoning
     * as FbtSuggestionProvider::getExcludedProductIds() (over 200 products
     * in this catalogue contain "free" or "decal" in their SKU; a LIKE
     * match would remove real sellable products). A SKU that resolves to
     * nothing is logged rather than ignored silently, for the same reason:
     * SKUs are editable, and a rename would otherwise turn this setting
     * into a silent no-op.
     *
     * @return int[]
     */
    private function getExcludedProductIds(): array
    {
        $configured = trim((string) $this->_scopeConfig->getValue(
            self::XML_PATH_EXCLUDED_SKUS,
            ScopeInterface::SCOPE_STORE
        ));
        if ($configured === '') {
            return [];
        }

        $skus = array_values(array_filter(array_map('trim', explode(',', $configured))));
        if (!$skus) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resourceConnection->getTableName('catalog_product_entity'), ['entity_id', 'sku'])
                ->where('sku IN (?)', $skus)
        );

        $found = [];
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['entity_id'];
            $found[] = (string) $row['sku'];
        }

        $missing = array_diff($skus, $found);
        if ($missing) {
            $this->logger->warning(
                '[PDPRevamp] CAB excluded_skus contains SKUs that match no product: '
                . implode(', ', $missing)
                . '. They are being ignored - check for a renamed product.'
            );
        }

        return $ids;
    }

    /**
     * The current product's own id for a simple product; its child
     * variant ids for a configurable; its associated/selection ids for
     * grouped/bundle. Mirrors Amasty\Mostviewed\Model\ProductProvider::
     * getProductIdsByType() exactly, so a configurable's purchase history
     * is gathered the same way Amasty gathered it.
     *
     * @return int[]
     */
    private function getAnchorProductIds(Product $product): array
    {
        $typeInstance = $product->getTypeInstance();

        switch ($product->getTypeId()) {
            case 'grouped':
                return array_map('intval', $typeInstance->getAssociatedProductIds($product));
            case 'configurable':
                return array_map('intval', $typeInstance->getUsedProductIds($product));
            case 'bundle':
                $optionIds = $typeInstance->getOptionsIds($product);
                $selections = $typeInstance->getSelectionsCollection($optionIds, $product);
                $ids = [];
                foreach ($selections as $selection) {
                    $ids[] = (int) $selection->getProductId();
                }
                return $ids;
            default:
                return [(int) $product->getId()];
        }
    }

    /**
     * pdp_cab_badge is a multiselect attribute, so getAttributeText() can't be
     * used to read it - Table::getOptionText() compares the whole stored value
     * ("5,7,9") against each option's single id and never matches, always
     * returning false for a multiselect. Resolving the raw comma-separated ids
     * against this option map (built once, not per product) is what actually
     * works for multiselect.
     *
     * @return array<string, string> option value id => label
     */
    private function getCabBadgeOptionsById(): array
    {
        if ($this->cabBadgeOptionsById === null) {
            $this->cabBadgeOptionsById = [];
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, 'pdp_cab_badge');
            foreach ($attribute->getSource()->getAllOptions(false) as $option) {
                $this->cabBadgeOptionsById[(string) $option['value']] = (string) $option['label'];
            }
        }

        return $this->cabBadgeOptionsById;
    }

    /**
     * @return string[] the admin-selected badge labels for this product, in
     *                   the order they were selected - empty when none are set.
     */
    private function getCabBadgeLabels(Product $product): array
    {
        $rawValue = (string) $product->getData('pdp_cab_badge');
        if ($rawValue === '') {
            return [];
        }

        $optionsById = $this->getCabBadgeOptionsById();
        $labels = [];
        foreach (explode(',', $rawValue) as $optionId) {
            if (isset($optionsById[$optionId])) {
                $labels[] = $optionsById[$optionId];
            }
        }

        return $labels;
    }

    private function getCurrentProduct(): Product
    {
        /** @var CurrentProduct $currentProduct */
        $currentProduct = $this->viewModelRegistry->require(CurrentProduct::class);
        return $currentProduct->get();
    }

    /**
     * Shop-title lookup for a set of seller (customer entity) ids, via the
     * same Webkul\Marketplace\Helper\Data::getSellerDataBySellerId() every
     * other seller-name spot on the PDP already uses (product-info.phtml,
     * details-seller-info.phtml, the FBT widget, Recently Viewed's
     * ProductBrands controller, and AdventureSeekersAlsoViewed) - rather
     * than a second hand-rolled marketplace_userdata query. The helper
     * already does the same "prefer the current store's row, fall back to
     * the store_id=0 default" lookup this used to reimplement by hand (see
     * Helper\Data::getSellerCollectionObj()).
     *
     * @param int[] $sellerIds
     * @return array<int, string>
     */
    private function getSellerNamesBySellerIds(array $sellerIds): array
    {
        $sellerIds = array_values(array_unique(array_filter(array_map('intval', $sellerIds))));
        if (!$sellerIds) {
            return [];
        }

        $namesBySellerId = [];
        foreach ($sellerIds as $sellerId) {
            $sellerData = $this->marketplaceHelper->getSellerDataBySellerId($sellerId)->getData()[0] ?? null;
            if (!$sellerData) {
                continue;
            }

            $shopTitle = (string) ($sellerData['shop_title'] ?? '');
            $name      = (string) ($sellerData['name'] ?? '');
            $display   = $shopTitle !== '' ? $shopTitle : ($name !== '' ? $name : null);
            if ($display !== null) {
                $namesBySellerId[$sellerId] = $display;
            }
        }

        return $namesBySellerId;
    }

    public function getGroupTitle(): ?string
    {
        return self::GROUP_NAME;
    }

    public function getSubtitle(): string
    {
        // Ensure getProducts() has run so $this->sourceTier reflects which
        // tier actually produced the current items, before reading it.
        $this->getItems();

        $sellerName = $this->getCurrentSellerName();

        switch ($this->sourceTier) {
            case 'purchase':
                return $sellerName
                    ? (string) __('Based on purchase data from %1 buyers', $sellerName)
                    : (string) __('Based on purchase data from Caliber Nation buyers');
            case 'seller':
                return $sellerName
                    ? (string) __('More from %1', $sellerName)
                    : (string) __('More from this seller');
            case 'department':
                return (string) __('More like this');
            case 'manual':
            default:
                return (string) __('Hand-picked for you');
        }
    }

    private function getCurrentSellerName(): ?string
    {
        if ($this->currentSellerNameResolved) {
            return $this->currentSellerName;
        }
        $this->currentSellerNameResolved = true;

        $currentProductId = (int) $this->getCurrentProduct()->getId();
        $sellerIdsByProductId = $this->dataProvider->getSellerIdsByProductIds([$currentProductId]);
        $sellerId = $sellerIdsByProductId[$currentProductId] ?? null;
        if ($sellerId === null) {
            return $this->currentSellerName = null;
        }

        $sellerNamesBySellerId = $this->getSellerNamesBySellerIds([$sellerId]);
        return $this->currentSellerName = $sellerNamesBySellerId[$sellerId] ?? null;
    }

    /**
     * Temporary debug wrapper - logs and rethrows any exception from
     * getProductsInner() so a failure surfaces in system.log even if
     * something upstream (Magento's layout renderer) swallows it silently.
     * Remove this wrapper once the "Customers Also Bought never renders"
     * investigation is done - rename getProductsInner() back to
     * getProducts() at that point.
     *
     * @return Product[]
     */
    private function getProducts(): array
    {
        try {
            return $this->getProductsInner();
        } catch (\Throwable $e) {
            $this->logger->error(
                '[PDPRevamp][CAB-DEBUG] getProducts() threw ' . get_class($e)
                . ': ' . $e->getMessage()
                . ' at ' . $e->getFile() . ':' . $e->getLine()
            );
            throw $e;
        }
    }

    /**
     * @return Product[]
     */
    private function getProductsInner(): array
    {
        $currentProduct = $this->getCurrentProduct();

        // Manual admin override always wins outright when set - it's a
        // deliberate merchandising choice, never blended with automatic
        // picks. See Ui\DataProvider\Product\Form\Modifier\ManualCarousels
        // for the product-edit grid that maintains this link.
        $manualProducts = $this->dataProvider->getManualFallbackProducts(
            $currentProduct,
            CreateManualCarouselLinkTypes::LINK_TYPE_MANUAL_CAB
        );
        $this->logger->info('[PDPRevamp][CAB-DEBUG] product ' . $currentProduct->getId()
            . ': manual tier = ' . count($manualProducts));
        if ($manualProducts) {
            $this->sourceTier = 'manual';
            return $manualProducts;
        }

        $anchorIds = $this->getAnchorProductIds($currentProduct);
        $storeId = (int) $this->storeManager->getStore()->getId();
        $currentProductId = (int) $currentProduct->getId();

        // Never show the current product back to itself - a configurable's
        // own order row (Magento always writes one alongside each child
        // variant's row) would otherwise qualify as a "companion" of its
        // own variants, since $anchorIds only contains the child ids, not
        // the configurable's own id. Also never show a configured
        // promotional SKU (e.g. a free giveaway that rides along with
        // most orders and would otherwise dominate every result).
        $excludeIds = array_merge($anchorIds, [$currentProductId], $this->getExcludedProductIds());

        $orderedIds = $anchorIds
            ? $this->purchaseAffinity->getCoBoughtProductIds($anchorIds, $excludeIds, $storeId, self::CANDIDATE_POOL_SIZE)
            : [];
        $purchaseProducts = $orderedIds
            ? $this->dataProvider->getProductsByIds($orderedIds, self::CANDIDATE_POOL_SIZE)
            : [];

        $this->sourceTier = $purchaseProducts ? 'purchase' : null;

        $this->logger->info('[PDPRevamp][CAB-DEBUG] product ' . $currentProductId
            . ': anchorIds = ' . count($anchorIds)
            . ', orderedIds (co-bought) = ' . count($orderedIds)
            . ', purchase tier products = ' . count($purchaseProducts));

        $remaining = self::CANDIDATE_POOL_SIZE - count($purchaseProducts);
        if ($remaining <= 0) {
            return $purchaseProducts;
        }

        // Purchase history alone didn't fill the pool (including the case
        // where it found nothing at all) - top up the rest automatically
        // rather than showing a thin carousel or falling straight to manual.
        $topUpExcludeIds = array_merge(
            $excludeIds,
            array_map(static fn (Product $p): int => (int) $p->getId(), $purchaseProducts)
        );

        // CAB deliberately does not check for a distributor SKU here - that
        // routing is Adventure Seekers Also Viewed's rule now. CAB always
        // tries the current product's own seller first, regardless of
        // whether that seller is a distributor-fed one.
        $autoTier = null;
        $autoProducts = [];

        $sellerId = $this->dataProvider->getSellerIdsByProductIds([$currentProductId])[$currentProductId] ?? null;
        if ($sellerId !== null) {
            $autoProducts = array_values(array_filter(
                $this->dataProvider->getProductsBySeller($sellerId, $currentProductId, $remaining + count($topUpExcludeIds)),
                static fn (Product $p): bool => !in_array((int) $p->getId(), $topUpExcludeIds, true)
            ));
            $autoProducts = array_slice($autoProducts, 0, $remaining);
            $autoTier = $autoProducts ? 'seller' : null;
        }
        $this->logger->info('[PDPRevamp][CAB-DEBUG] product ' . $currentProductId
            . ': sellerId = ' . ($sellerId ?? 'null')
            . ', seller tier products = ' . count($autoProducts));

        // Seller alone may not fill every remaining slot - it might
        // have no products at all, or only a handful (a thin catalog).
        // Either way, top up whatever's still missing from the same
        // department so a small seller doesn't leave the carousel
        // looking sparse.
        $stillRemaining = $remaining - count($autoProducts);
        if ($stillRemaining > 0) {
            $departmentExcludeIds = array_merge(
                $topUpExcludeIds,
                array_map(static fn (Product $p): int => (int) $p->getId(), $autoProducts)
            );
            $departmentProducts = $this->getDepartmentProductsExcluding(
                $currentProduct,
                $departmentExcludeIds,
                $stillRemaining
            );
            if ($departmentProducts) {
                $autoProducts = array_merge($autoProducts, $departmentProducts);
                $autoTier = $autoTier ?? 'department';
            }
            $this->logger->info('[PDPRevamp][CAB-DEBUG] product ' . $currentProductId
                . ': department top-up products = ' . count($departmentProducts));
        }

        if (!$purchaseProducts && $autoProducts) {
            $this->sourceTier = $autoTier;
        }

        $finalProducts = array_merge($purchaseProducts, $autoProducts);
        $this->logger->info('[PDPRevamp][CAB-DEBUG] product ' . $currentProductId
            . ': getProducts() total candidates = ' . count($finalProducts)
            . ', sourceTier = ' . ($this->sourceTier ?? 'null'));

        return $finalProducts;
    }

    /**
     * @param int[] $excludeIds
     * @return Product[]
     */
    private function getDepartmentProductsExcluding(Product $currentProduct, array $excludeIds, int $limit): array
    {
        $candidates = $this->dataProvider->getProductsByDepartment($currentProduct, $limit + count($excludeIds));
        $filtered = array_values(array_filter(
            $candidates,
            static fn (Product $p): bool => !in_array((int) $p->getId(), $excludeIds, true)
        ));

        return array_slice($filtered, 0, $limit);
    }

    public function getItems(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }

        $products = $this->getProducts();
        $bestsellerIds = $this->dataProvider->getBestsellerProductIds();

        $productIds    = array_map(static fn (Product $p): int => (int) $p->getId(), $products);
        $reviewSummaries = $this->dataProvider->getReviewSummaryByProductIds($productIds);

        $currentProductId = (int) $this->getCurrentProduct()->getId();
        $sellerIdsByProductId = $this->dataProvider->getSellerIdsByProductIds(array_merge(
            [$currentProductId],
            $productIds
        ));
        $currentSellerId = $sellerIdsByProductId[$currentProductId] ?? null;
        $sellerNamesBySellerId = $this->getSellerNamesBySellerIds(array_values($sellerIdsByProductId));

        $rawItems = [];
        $rejected = ['notSaleable' => 0, 'tooExpensive' => 0, 'lowStock' => 0, 'noSeller' => 0];
        foreach ($products as $product) {
            /** @var Product $product */
            if (!$product->isSaleable()) {
                $rejected['notSaleable']++;
                continue;
            }

            $finalPrice    = (float) $product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
            $regularPrice  = (float) $product->getPriceInfo()->getPrice('regular_price')->getAmount()->getValue();

            // Only show items priced under $999.
            if ($finalPrice >= 999.0) {
                $rejected['tooExpensive']++;
                continue;
            }

            $pid = (int) $product->getId();

            // Only show items with more than 8 units in stock - also
            // excludes out-of-stock items (qty 0 falls under this same
            // check). Configurable-aware: a configurable candidate's own
            // stock item is normally empty (real qty lives on its child
            // variants), so this checks any salable child instead of just
            // reading the parent's own record - see
            // RelatedCarouselDataProvider::hasStockAbove().
            if (!$this->dataProvider->hasStockAbove($product, 8.0)) {
                $rejected['lowStock']++;
                continue;
            }

            $sid = $sellerIdsByProductId[$pid] ?? null;

            // Product must have a marketplace seller assigned.
            if ($sid === null) {
                $rejected['noSeller']++;
                continue;
            }

            $requiredOptions  = (bool) $product->getTypeInstance()->hasRequiredOptions($product);
            $reviewData       = $reviewSummaries[$pid] ?? null;
            $ratingSummary    = $reviewData ? $reviewData['rating'] : 0.0;
            $reviewsCount     = $reviewData ? $reviewData['count'] : 0;
            $isBestseller     = isset($bestsellerIds[$pid]);
            $isTopRated       = $ratingSummary >= 90.0;
            $isSameSeller     = $currentSellerId !== null && $sid === $currentSellerId;
            $badgeOverrides   = $this->getCabBadgeLabels($product);

            $rawItems[] = [
                'id'              => $pid,
                'sku'             => $product->getSku(),
                'name'            => html_entity_decode((string) $product->getName(), ENT_QUOTES),
                'image'           => $this->imageHelper
                    ->init($product, 'ahy_adventure_seekers_thumbnail')
                    ->setImageFile($product->getData('small_image'))
                    ->getUrl(),
                'finalPrice'      => round($finalPrice, 2),
                'regularPrice'    => round($regularPrice, 2),
                'hasDiscount'     => $regularPrice > $finalPrice,
                'url'             => $product->getProductUrl(),
                'ratingSummary'   => $ratingSummary,
                'reviewsCount'    => $reviewsCount,
                'isDirectlyAddable' => $product->getTypeId() === 'simple'
                    && $product->isSaleable()
                    && !$requiredOptions,
                'isConfigurable'  => $product->getTypeId() === 'configurable' && $product->isSaleable(),
                'addToCartUrl'    => $this->getUrl('checkout/cart/add', [
                    '_secure' => true,
                    'product' => $product->getId(),
                ]),
                'isBestseller'    => $isBestseller,
                'isTopRated'      => $isTopRated,
                'isSameSeller'    => $isSameSeller,
                'badgeOverride'   => $badgeOverrides,
                'sellerName'      => $this->dataProvider->resolveDisplayBrand($product, $sid ? ($sellerNamesBySellerId[$sid] ?? null) : null),
            ];
        }

        $this->logger->info('[PDPRevamp][CAB-DEBUG] product ' . $currentProductId
            . ': candidates in = ' . count($products)
            . ', survived filters = ' . count($rawItems)
            . ', rejected = ' . json_encode($rejected));

        // $rawItems is already ranked by co-purchase count (the order
        // $products arrived in, from PurchaseAffinity) - just cut to the
        // display cap, no re-sorting needed.
        $rawItems = array_slice($rawItems, 0, self::MAX_PRODUCTS);

        // Under $X badge: target the 2nd-cheapest item in this grid.
        $sortedByPrice = $rawItems;
        usort($sortedByPrice, fn (array $a, array $b) => $a['finalPrice'] <=> $b['finalPrice']);
        $underPriceTargetId  = isset($sortedByPrice[1]) ? $sortedByPrice[1]['id'] : null;
        $underPriceBadgeValue = $underPriceTargetId !== null
            ? (int) floor($sortedByPrice[1]['finalPrice']) + 1
            : null;

        $this->items = [];
        foreach ($rawItems as $item) {
            if (!empty($item['badgeOverride'])) {
                // Any admin-selected pdp_cab_badge values always override the
                // automatic badges below for this product - a product can
                // show more than one at once (e.g. both "Trending" and
                // "Essential"). A selected label with no color entry in
                // CAB_BADGE_COLORS is silently dropped rather than shown.
                $badges = [];
                foreach ($item['badgeOverride'] as $label) {
                    if (isset(self::CAB_BADGE_COLORS[$label])) {
                        $badges[] = ['label' => $label, 'color' => self::CAB_BADGE_COLORS[$label]];
                    }
                }
            } else {
                $badges = [];
                if ($item['isBestseller']) {
                    $badges[] = ['label' => (string) __('Best Seller'), 'color' => '#E75C26'];
                }
                if ($item['isTopRated']) {
                    $badges[] = ['label' => (string) __('Top Rated'), 'color' => '#E75C26'];
                }
                if ($item['isSameSeller']) {
                    $badges[] = ['label' => (string) __('Same Seller'), 'color' => '#0d2f47'];
                }
                if ($item['id'] === $underPriceTargetId) {
                    $badges[] = ['label' => (string) __('Under $%1', $underPriceBadgeValue), 'color' => '#16a34a'];
                }
            }

            unset($item['isBestseller'], $item['isTopRated'], $item['isSameSeller'], $item['badgeOverride']);
            $item['badges'] = $badges;
            $this->items[]  = $item;
        }

        return $this->items;
    }
}
