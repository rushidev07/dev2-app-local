<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Model\Product;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\LinkFactory as ProductLinkModelFactory;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Review\Model\ResourceModel\Review\Summary\CollectionFactory as ReviewSummaryCollectionFactory;
use Magento\Sales\Model\ResourceModel\Report\Bestsellers\CollectionFactory as BestsellersCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Webkul\Marketplace\Model\ResourceModel\Product\CollectionFactory as MpProductCollectionFactory;

/**
 * Shared lookups used by the PDP's related-product carousels
 * (CustomersAlsoBought, AdventureSeekersAlsoViewed): department/purchase
 * sourcing, seller-id batching, review-summary batching, and the
 * bestseller-id set. Extracted so both blocks stay in sync instead of
 * maintaining two copies of the same lookups.
 *
 * Neither carousel reads from Amasty_Mostviewed any more - each sources
 * its own candidates (department category tree for ASAV, order history
 * for CAB, see getProductsByDepartment()/getProductsByIds()) and falls
 * back to its own manual admin-curated link type when that source has
 * nothing. Amasty is still installed and enabled, just unused by this
 * module now.
 *
 * Each method memoizes within this instance. Since Magento shares one
 * instance of a plain class per request by default, this also means the
 * unbounded bestseller collection now loads once per request instead of
 * once per carousel when both render on the same page.
 *
 * getSellerNamesBySellerIds() is deliberately NOT here: the two blocks'
 * versions differ (store-scoped seller_id lookup vs unscoped entity_id
 * lookup with a name fallback), and unifying them would change one
 * block's behavior, so each keeps its own copy.
 */
class RelatedCarouselDataProvider
{
    /**
     * Matches the price ceiling / stock floor every carousel's own getItems()
     * loop already enforces in PHP (CustomersAlsoBought, AdventureSeekers
     * AlsoViewed). Applying the same thresholds here too, at the database
     * level, means orderRand() picks from a pool that's already known to
     * qualify - instead of randomly sampling $limit candidates from a
     * department/seller catalog where, in practice, well under 5% of
     * products end up passing price+stock+seller (confirmed against a real
     * 46k-product department: only 464 qualified), which left carousels
     * showing a handful of items even though the site had plenty of
     * genuinely eligible products. The PHP-side checks stay in place as a
     * safety net for candidates from sources that don't go through this
     * class's collections at all (purchase history, bestsellers, manual
     * picks) - this is purely about not wasting the random sample here.
     */
    private const CANDIDATE_MAX_PRICE = 999.0;
    private const CANDIDATE_MIN_STOCK = 8.0;

    private BestsellersCollectionFactory $bestsellersCollectionFactory;
    private MpProductCollectionFactory $mpProductCollectionFactory;
    private ReviewSummaryCollectionFactory $reviewSummaryCollectionFactory;
    private StoreManagerInterface $storeManager;
    private ProductLinkModelFactory $productLinkModelFactory;
    private CategoryCollectionFactory $categoryCollectionFactory;
    private ProductCollectionFactory $productCollectionFactory;
    private StockRegistryInterface $stockRegistry;

    /** @var array<int, true>|null */
    private ?array $bestsellerProductIds = null;

    public function __construct(
        BestsellersCollectionFactory $bestsellersCollectionFactory,
        MpProductCollectionFactory $mpProductCollectionFactory,
        ReviewSummaryCollectionFactory $reviewSummaryCollectionFactory,
        StoreManagerInterface $storeManager,
        ProductLinkModelFactory $productLinkModelFactory,
        CategoryCollectionFactory $categoryCollectionFactory,
        ProductCollectionFactory $productCollectionFactory,
        StockRegistryInterface $stockRegistry
    ) {
        $this->bestsellersCollectionFactory = $bestsellersCollectionFactory;
        $this->mpProductCollectionFactory = $mpProductCollectionFactory;
        $this->reviewSummaryCollectionFactory = $reviewSummaryCollectionFactory;
        $this->storeManager = $storeManager;
        $this->productLinkModelFactory = $productLinkModelFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->stockRegistry = $stockRegistry;
    }

    /**
     * Admin-picked fallback products for a manual link type (see
     * Setup\Patch\Data\CreateManualCarouselLinkTypes) - used only when a
     * carousel's own automatic sourcing has nothing for this product,
     * never as an override of real data. Ordered by the position set in
     * the product-picker grid on the product edit page.
     *
     * Reads via the older, generic Model\Product\Link mechanism (any
     * link_type_id, straight SQL join on catalog_product_link) rather than
     * ProductLinkRepositoryInterface::getList(), which only recognizes link
     * types registered with Magento\Catalog\Model\ProductLink\
     * CollectionProvider - a second hardcoded registry beyond
     * LinkTypeProvider that a custom link type would also need to satisfy.
     *
     * @return Product[]
     */
    public function getManualFallbackProducts(Product $currentProduct, int $linkTypeId): array
    {
        try {
            $linkModel = $this->productLinkModelFactory->create();
            $linkModel->setLinkTypeId($linkTypeId);

            $collection = $linkModel->getProductCollection();
            $collection->setProduct($currentProduct);
            $collection->addAttributeToSelect(['name', 'price', 'special_price', 'image', 'small_image']);
            $collection->setPositionOrder();

            return array_values(iterator_to_array($collection));
        } catch (\Exception $e) {
            // This is a fallback for when Amasty has nothing - it must never
            // take the whole PDP down if the product-link read itself fails.
            return [];
        }
    }

    /**
     * @return array<int, true>
     */
    public function getBestsellerProductIds(): array
    {
        if ($this->bestsellerProductIds !== null) {
            return $this->bestsellerProductIds;
        }

        /** @var \Magento\Sales\Model\ResourceModel\Report\Bestsellers\Collection $collection */
        $collection = $this->bestsellersCollectionFactory->create();
        $collection->load();

        $ids = [];
        foreach ($collection as $row) {
            $ids[(int) $row->getData('product_id')] = true;
        }

        return $this->bestsellerProductIds = $ids;
    }

    /**
     * @param int[] $productIds
     * @return array<int, int>
     */
    public function getSellerIdsByProductIds(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if (!$productIds) {
            return [];
        }

        // A single mageproduct_id can have more than one marketplace_product
        // row (a product assigned to multiple sellers) - order by entity_id
        // and keep only the first row per product, same as every other
        // seller lookup on this PDP (product-info.phtml, details-seller-info.
        // phtml both use getSellerProductDataByProductId()->getData()[0]).
        // Without this, an unordered scan could keep a *different* row than
        // those templates resolve, making this carousel think the current
        // product belongs to the wrong seller - and badge an unrelated
        // product as "Same Seller".
        $collection = $this->mpProductCollectionFactory->create();
        $collection->addFieldToFilter('mageproduct_id', ['in' => $productIds]);
        $collection->setOrder('entity_id', 'ASC');

        $sellerIdsByProductId = [];
        foreach ($collection as $sellerProduct) {
            $pid = (int) $sellerProduct->getData('mageproduct_id');
            if (isset($sellerIdsByProductId[$pid])) {
                continue;
            }
            $sellerIdsByProductId[$pid] = (int) $sellerProduct->getData('seller_id');
        }

        return $sellerIdsByProductId;
    }

    /**
     * @param int[] $productIds
     * @return array<int, array{rating: float, count: int}>
     */
    public function getReviewSummaryByProductIds(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }

        $storeId    = (int) $this->storeManager->getStore()->getId();
        $collection = $this->reviewSummaryCollectionFactory->create();
        $collection->addFieldToFilter('entity_pk_value', ['in' => $productIds]);
        $collection->addFieldToFilter('store_id', $storeId);

        $result = [];
        foreach ($collection as $summary) {
            $count = (int) $summary->getData('reviews_count');
            if ($count > 0) {
                $result[(int) $summary->getData('entity_pk_value')] = [
                    'rating' => (float) $summary->getData('rating_summary'),
                    'count'  => $count,
                ];
            }
        }

        return $result;
    }

    /**
     * Products from the current product's "department" - the top-level
     * category directly under the store's root category (e.g. a T-shirt
     * filed under Apparel > Graphic Tees counts as "Apparel", regardless
     * of how deep its actual assigned category sits). Replaces the old
     * Amasty-driven "Adventure Seekers Also Viewed" group, whose category
     * condition never actually applied (see Amasty\Mostviewed\Model\
     * Group::applySameAsConditions() reading the wrong data key) and whose
     * "parent category" recheck in the block compared immediate parents
     * only, so sibling categories under the same parent still leaked in.
     *
     * Only the product's FIRST assigned category decides its department -
     * deliberately not every category it's tagged into. A product is
     * often tagged into extra categories purely for merchandising (a
     * "Sale" or "New Arrivals" shelf, say), and unioning departments
     * across all of them let an unrelated promotional category's own
     * department leak into the candidate pool, which is exactly what
     * "the category is not mapping properly" turned out to mean here.
     *
     * @return Product[]
     */
    public function getProductsByDepartment(Product $currentProduct, int $limit): array
    {
        $categoryIds = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) $currentProduct->getCategoryIds()
        ))));
        if (!$categoryIds) {
            return [];
        }
        $primaryCategoryId = $categoryIds[0];

        $rootCategoryId = (string) $this->storeManager->getStore()->getRootCategoryId();

        $primaryCategoryCollection = $this->categoryCollectionFactory->create();
        $primaryCategoryCollection->addFieldToFilter('entity_id', $primaryCategoryId);
        $primaryCategoryCollection->addAttributeToSelect('path');
        $primaryCategory = $primaryCategoryCollection->getFirstItem();
        if (!$primaryCategory->getId()) {
            return [];
        }

        $path = explode('/', (string) $primaryCategory->getPath());
        $rootPosition = array_search($rootCategoryId, $path, true);
        if ($rootPosition === false || !isset($path[$rootPosition + 1])) {
            return [];
        }
        $departmentId = (int) $path[$rootPosition + 1];

        $departmentCollection = $this->categoryCollectionFactory->create();
        $departmentCollection->addFieldToFilter('entity_id', $departmentId);
        $departmentCollection->addAttributeToSelect('path');
        $department = $departmentCollection->getFirstItem();
        if (!$department->getId()) {
            return [];
        }

        $categoryTreeIds = [$departmentId];
        $descendants = $this->categoryCollectionFactory->create();
        $descendants->addFieldToFilter('path', ['like' => $department->getPath() . '/%']);
        foreach ($descendants as $descendant) {
            $categoryTreeIds[] = (int) $descendant->getId();
        }
        $categoryTreeIds = array_values(array_unique($categoryTreeIds));

        $productCollection = $this->productCollectionFactory->create();
        $productCollection->addAttributeToSelect([
            'name',
            'price',
            'special_price',
            'special_from_date',
            'special_to_date',
            'image',
            'small_image',
            'product_brand',
        ]);
        $productCollection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        $productCollection->addAttributeToFilter('visibility', ['neq' => Visibility::VISIBILITY_NOT_VISIBLE]);
        $productCollection->addAttributeToFilter('price', ['lt' => self::CANDIDATE_MAX_PRICE]);
        $productCollection->addFieldToFilter('entity_id', ['neq' => (int) $currentProduct->getId()]);
        $productCollection->addCategoriesFilter(['in' => $categoryTreeIds]);
        $this->requireMarketplaceSeller($productCollection);
        $this->applyStockAboveFilter($productCollection, self::CANDIDATE_MIN_STOCK);
        $productCollection->setPageSize($limit);
        $productCollection->getSelect()->orderRand();

        return array_values(iterator_to_array($productCollection));
    }

    /**
     * Other saleable products from the same marketplace seller's catalog -
     * the CAB auto-populate source for a normal (non-distributor) product
     * once real co-purchase data has been exhausted. Uses joinSellerProducts()
     * on the product collection rather than Webkul\Marketplace\Helper\Data::
     * getSellerProductCollection(), since that helper hardcodes a 5-item cap
     * and branches its exclude-current-product filter only when the caller
     * asks for more than 5 - both tuned for a different storefront widget.
     * $productCollectionFactory already resolves to Webkul's own rewrite
     * (Magento\Catalog\Model\ResourceModel\Product\Collection is preferenced
     * to Webkul\Marketplace\Model\Rewrite\Catalog\ResourceModel\Product\
     * Collection - see Webkul\Marketplace\etc\di.xml), so joinSellerProducts()
     * is available here with no new DI dependency.
     *
     * @return Product[]
     */
    public function getProductsBySeller(int $sellerId, int $excludeProductId, int $limit): array
    {
        if ($sellerId < 1 || $limit < 1) {
            return [];
        }

        $productCollection = $this->productCollectionFactory->create();
        $productCollection->addAttributeToSelect([
            'name',
            'price',
            'special_price',
            'special_from_date',
            'special_to_date',
            'image',
            'small_image',
            'product_brand',
            'pdp_cab_badge',
        ]);
        $productCollection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        $productCollection->addAttributeToFilter('visibility', ['neq' => Visibility::VISIBILITY_NOT_VISIBLE]);
        $productCollection->addAttributeToFilter('price', ['lt' => self::CANDIDATE_MAX_PRICE]);
        // Join marketplace_product directly rather than calling Webkul's own
        // joinSellerProducts() - that method also hardcodes its own
        // visibility filter (addAttributeToFilter('visibility', ['in' =>
        // [4]]), i.e. "Catalog, Search" only) and a store filter, both
        // narrower than the visibility rule used everywhere else on this
        // PDP (just "not not-visible", same as getProductsByDepartment()
        // above). That extra restriction silently excluded a seller's
        // "Catalog Only"-visibility products from ever appearing here, even
        // though they render normally everywhere else on the site - joining
        // the table ourselves keeps this method's own filters authoritative.
        $productCollection->getSelect()->joinInner(
            ['mp_product' => $productCollection->getTable('marketplace_product')],
            'mp_product.mageproduct_id = e.entity_id',
            []
        );
        // Two separate where() calls, not one condition with two "?"
        // placeholders - Select::where() only binds a single value per
        // call (the 2nd argument), so a second "?" would silently fall
        // through to the unrelated $type argument instead of being bound.
        $productCollection->getSelect()->where('mp_product.seller_id = ?', $sellerId);
        $productCollection->getSelect()->where('e.entity_id != ?', $excludeProductId);
        $this->applyStockAboveFilter($productCollection, self::CANDIDATE_MIN_STOCK);
        $productCollection->setPageSize($limit);
        $productCollection->getSelect()->orderRand();

        return array_values(iterator_to_array($productCollection));
    }

    /**
     * Hydrates a ranked list of product ids (best match first, from e.g.
     * PurchaseAffinity::getCoBoughtProductIds()) into product models,
     * preserving that rank - a collection load does not preserve input
     * order on its own, so ids are iterated separately from the
     * collection rather than trusting iteration order.
     *
     * @param int[] $orderedIds ranked product ids, best first
     * @return Product[]
     */
    public function getProductsByIds(array $orderedIds, int $limit): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderedIds))));
        if (!$ids || $limit < 1) {
            return [];
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect([
            'name',
            'price',
            'special_price',
            'special_from_date',
            'special_to_date',
            'image',
            'small_image',
            'product_brand',
            'pdp_cab_badge',
        ]);
        $collection->addFieldToFilter('entity_id', ['in' => $ids]);

        $productsById = [];
        foreach ($collection as $product) {
            $productsById[(int) $product->getId()] = $product;
        }

        $products = [];
        foreach ($ids as $id) {
            if (count($products) >= $limit) {
                break;
            }
            if (isset($productsById[$id])) {
                $products[] = $productsById[$id];
            }
        }

        return $products;
    }

    /**
     * Label shown under the product name: the "product_brand" attribute's
     * label if the product has one, otherwise the marketplace seller's shop
     * name, otherwise '' - a product with neither gets no label at all
     * rather than a misleading "Everest" default, since it has no real
     * seller to attribute it to.
     */
    public function resolveDisplayBrand(Product $product, ?string $sellerName): string
    {
        $brand = (string) $product->getAttributeText('product_brand');
        if ($brand !== '' && $brand !== 'No') {
            return $brand;
        }

        return $sellerName ?? '';
    }

    /**
     * SKU-pattern check for "sourced from a distributor" (client-specified
     * rule, e.g. Bill Hicks / CWR feeds routed through the "Everest
     * Marketplace" placeholder seller). Used by AdventureSeekersAlsoViewed
     * to decide whether a same-seller top-up applies once the department
     * pool needs filling - a distributor isn't a real merchandising seller
     * the way a marketplace vendor is, so those products stay department-
     * only. CustomersAlsoBought does NOT use this check - it always tries
     * the current product's own seller regardless of distributor status.
     */
    public function isDistributorProduct(Product $product): bool
    {
        $sku = strtolower((string) $product->getSku());

        if (str_starts_with($sku, 'cwr-')) {
            return true;
        }

        return str_starts_with($sku, 'bil-') && str_ends_with($sku, '-1');
    }

    /**
     * Whether this candidate has more than $threshold units available -
     * configurable-aware, since a configurable's own stock item is normally
     * empty (real quantity lives on its child variants, not the parent), so
     * reading the parent's own qty directly always fails a ">N units" check
     * even when a child variant genuinely has stock. Checked against ANY
     * salable child having enough stock, matching how stock-status.phtml's
     * own "X of Y in stock" count already treats configurables.
     *
     * Simple/other product types just read their own stock item directly,
     * same as before this method existed.
     */
    public function hasStockAbove(Product $product, float $threshold): bool
    {
        if ($product->getTypeId() === Configurable::TYPE_CODE) {
            foreach ($product->getTypeInstance()->getUsedProducts($product) as $child) {
                $childStock = $this->stockRegistry->getStockItem($child->getId());
                if ($childStock->getIsInStock() && (float) $childStock->getQty() > $threshold) {
                    return true;
                }
            }

            return false;
        }

        $stockItem = $this->stockRegistry->getStockItem($product->getId());

        return (float) $stockItem->getQty() > $threshold;
    }

    /**
     * Restricts a candidate collection to products that have ANY marketplace
     * seller assigned, pushed down to SQL rather than relying on the
     * getProductsBySeller()-style seller-specific join, since here we only
     * need to confirm a seller exists at all (department candidates aren't
     * tied to one particular seller).
     *
     * Uses WHERE EXISTS rather than a JOIN: a product can have more than one
     * marketplace_product row (assigned to multiple sellers - see the note
     * on getSellerIdsByProductIds()), and a JOIN would return that product's
     * row once per match, causing the collection loader to see the same
     * entity_id twice and throw "Item ... already exists". EXISTS only
     * tests presence, so it can't duplicate rows.
     */
    private function requireMarketplaceSeller(ProductCollection $productCollection): void
    {
        $mpTable = $productCollection->getTable('marketplace_product');

        $productCollection->getSelect()->where(
            "EXISTS (SELECT 1 FROM {$mpTable} AS mp_dept_filter"
            . ' WHERE mp_dept_filter.mageproduct_id = e.entity_id)'
        );
    }

    /**
     * SQL-level version of hasStockAbove(): restricts a candidate collection
     * to products with more than $threshold units available, configurable-
     * aware via EXISTS against child variants. Pushed down to the query so
     * orderRand() samples from an already-qualifying pool instead of pulling
     * random candidates that mostly fail this check in PHP afterward.
     *
     * $threshold is embedded via quote() rather than a "?" placeholder,
     * since Select::where() only binds one value per call even when a
     * condition string has multiple "?"s - see hasStockAbove() callers for
     * the PHP-side equivalent this mirrors.
     */
    private function applyStockAboveFilter(ProductCollection $productCollection, float $threshold): void
    {
        $connection = $productCollection->getConnection();
        $stockTable = $productCollection->getTable('cataloginventory_stock_item');
        $superLinkTable = $productCollection->getTable('catalog_product_super_link');
        $quotedThreshold = $connection->quote($threshold);

        $productCollection->getSelect()->joinLeft(
            ['own_stock' => $stockTable],
            'own_stock.product_id = e.entity_id',
            []
        );

        $productCollection->getSelect()->where(
            "(e.type_id != 'configurable' AND own_stock.qty > {$quotedThreshold})"
            . " OR (e.type_id = 'configurable' AND EXISTS ("
            . "SELECT 1 FROM {$superLinkTable} AS csl"
            . " INNER JOIN {$stockTable} AS child_stock ON child_stock.product_id = csl.product_id"
            . " WHERE csl.parent_id = e.entity_id AND child_stock.qty > {$quotedThreshold} AND child_stock.is_in_stock = 1"
            . "))"
        );
    }
}


