<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Service;

use Ahy\PDPRevamp\Model\Product\RelatedCarouselDataProvider;
use Ahy\PDPRevamp\Model\ResourceModel\ProductAffinity;
use Ahy\PDPRevamp\Setup\Patch\Data\CreateManualFbtLinkType;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Webkul\Marketplace\Helper\Data as MarketplaceHelper;

/**
 * Decides which products appear beside the current one in the PDP's
 * "Frequently Bought Together" section.
 *
 * Three tiers, in this order:
 *
 *   1. Manual override - products an admin picked on the product edit page via
 *      link type 92 (Setup\Patch\Data\CreateManualFbtLinkType). When the admin
 *      has set any picks, they win outright and no other tier is consulted.
 *      This matches the CAB section's tier-1 behaviour and gives merchants
 *      full control over the bundle without order-history interference.
 *
 *   2. Co-purchase - products actually bought alongside this one, sourced from
 *      order history (Model\ResourceModel\ProductAffinity). Only reached when
 *      no manual picks are set.
 *
 *   3. Seller / department auto-fill - when co-purchase does not fill the
 *      required slots (or finds nothing at all), other products from the same
 *      marketplace seller are shown, topped up from the product's department
 *      (top-level category under the store root) when the seller catalog is
 *      thin. Distributor SKUs (cwr-*, bil-*-1) skip the seller tier and route
 *      straight to the department tree, matching the CAB section's distributor
 *      routing. All auto-fill candidates still go through the same salability,
 *      stock, and price gates as co-purchase results.
 *
 * If none of the tiers yield anything the caller gets an empty array and the
 * section is not rendered at all.
 *
 * Note: the pdp_fbt_force_manual product attribute is now a no-op. Since the
 * admin manual tier always wins first, explicitly "forcing" manual is the
 * default behaviour whenever any picks exist. The attribute is preserved in
 * the database for backwards compatibility but is no longer read here.
 */
class FbtSuggestionProvider
{
    private const XML_PATH_MAX_SUGGESTIONS = 'pdprevamp_fbt/general/max_suggestions';
    private const XML_PATH_MIN_COPURCHASE = 'pdprevamp_fbt/general/min_copurchase_orders';
    private const XML_PATH_EXCLUDED_SKUS = 'pdprevamp_fbt/general/excluded_skus';

    private const DEFAULT_MAX_SUGGESTIONS = 2;

    /**
     * Suggestions priced at or above this are excluded - the section is meant
     * for add-on items, not big-ticket ones. Matches the ceiling used by the
     * Adventure Seekers Also Viewed carousel.
     */
    private const MAX_SUGGESTION_PRICE = 999.0;

    /**
     * Suggestions with this many units in stock or fewer are excluded, not
     * just ones at zero. Matches Adventure Seekers Also Viewed - a suggestion
     * that sells out mid-checkout is worse than one that never appeared.
     */
    private const MIN_STOCK_QTY = 8.0;

    private ProductAffinity $productAffinity;
    private RelatedCarouselDataProvider $relatedCarouselDataProvider;
    private CollectionFactory $productCollectionFactory;
    private ResourceConnection $resourceConnection;
    private ScopeConfigInterface $scopeConfig;
    private LoggerInterface $logger;
    private MarketplaceHelper $marketplaceHelper;
    private StockRegistryInterface $stockRegistry;
    private Configurable $configurableType;

    /**
     * Salable variants per configurable id, memoised: the salability and the
     * stock checks below both need them, and getUsedProducts() loads a
     * collection on every call.
     *
     * @var array<int, Product[]>
     */
    private array $salableVariants = [];

    /** Memoised; 0 means looked up and absent. */
    private ?int $positionAttributeId = null;

    public function __construct(
        ProductAffinity $productAffinity,
        RelatedCarouselDataProvider $relatedCarouselDataProvider,
        CollectionFactory $productCollectionFactory,
        ResourceConnection $resourceConnection,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        MarketplaceHelper $marketplaceHelper,
        StockRegistryInterface $stockRegistry,
        Configurable $configurableType
    ) {
        $this->productAffinity = $productAffinity;
        $this->relatedCarouselDataProvider = $relatedCarouselDataProvider;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->resourceConnection = $resourceConnection;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->marketplaceHelper = $marketplaceHelper;
        $this->stockRegistry = $stockRegistry;
        $this->configurableType = $configurableType;
    }

    /**
     * @return Product[] empty when there is nothing trustworthy to show
     */
    public function getSuggestions(Product $product): array
    {
        $limit = $this->getMaxSuggestions();
        if ($limit < 1) {
            return [];
        }

        $productId = (int) $product->getId();
        if ($productId < 1) {
            return [];
        }

        $excludedIds = $this->getExcludedProductIds();
        $excludedIds[] = $productId;

        // TIER 1 – Admin manual override always wins outright when set.
        // If the admin has picked any product in the "Frequently Bought
        // Together (Manual)" grid on the product edit page, use only those
        // picks and skip all other tiers. Same semantics as CAB's tier 1:
        // a deliberate merchandising choice is never blended with automatic
        // picks. The picks still go through loadValidProducts() so a
        // disabled / out-of-stock / overpriced manual pick is silently
        // skipped rather than surfacing a broken card.
        $manualIds = array_values(array_diff($this->getManualProductIds($product), $excludedIds));
        if ($manualIds) {
            return $this->loadValidProducts($manualIds, $excludedIds, $limit);
        }

        // TIER 2 – Co-purchase data: products actually bought alongside this one.
        $candidateIds = $this->productAffinity->getRelatedProductIds(
            $productId,
            // Over-fetch: some ids will fail the salable/simple/price checks
            // below, and re-querying per rejection would be worse.
            $limit * 4,
            $excludedIds,
            $this->getMinCoPurchaseOrders()
        );

        $suggestions = $this->loadValidProducts($candidateIds, $excludedIds, $limit);

        if (count($suggestions) >= $limit) {
            return $suggestions;
        }

        // TIER 3 – Seller / department auto-fill.
        // Co-purchase did not fill all slots (or found nothing at all).
        // Follow the same distributor-aware routing as CAB: distributor SKUs
        // go straight to the department tree; normal products try the seller
        // catalog first and top up from the department if needed.
        $alreadyChosen = array_map(static fn (Product $p): int => (int) $p->getId(), $suggestions);
        $autoFillExcludeIds = array_merge($excludedIds, $alreadyChosen);
        $remaining = $limit - count($suggestions);

        $autoProducts = $this->getAutoFillProducts($product, $autoFillExcludeIds, $remaining);

        return array_merge($suggestions, $autoProducts);
    }

    /**
     * Tier 3 auto-fill: seller catalog (non-distributor) or department tree
     * (distributor), mirroring CAB's getProducts() distributor routing.
     *
     * Products sourced here still go through loadValidProducts() so every
     * auto-fill candidate passes the same stock, price, and seller gates as
     * co-purchase results.
     *
     * @param int[] $excludeIds
     * @return Product[]
     */
    private function getAutoFillProducts(Product $product, array $excludeIds, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        if ($this->isDistributorProduct($product)) {
            // Distributor products are routed through a placeholder seller
            // (e.g. "Everest Marketplace") rather than a real merchandising
            // seller, so route straight to the department tree - same as CAB.
            return $this->getAutoFillByDepartment($product, $excludeIds, $limit);
        }

        $productId = (int) $product->getId();
        $sellerProducts = [];

        $sellerId = $this->relatedCarouselDataProvider
            ->getSellerIdsByProductIds([$productId])[$productId] ?? null;

        if ($sellerId !== null) {
            // Over-fetch from the seller so that the exclude filter and the
            // loadValidProducts() gate have enough candidates to fill $limit.
            $rawSeller = $this->relatedCarouselDataProvider->getProductsBySeller(
                $sellerId,
                $productId,
                $limit + count($excludeIds)
            );
            $sellerIds = array_values(array_diff(
                array_map(static fn (Product $p): int => (int) $p->getId(), $rawSeller),
                $excludeIds
            ));
            $sellerProducts = $this->loadValidProducts($sellerIds, $excludeIds, $limit);
        }

        $stillRemaining = $limit - count($sellerProducts);
        if ($stillRemaining <= 0) {
            return $sellerProducts;
        }

        // The seller catalog didn't fill every remaining slot - it might be
        // thin, or have no eligible products at all. Top up from the same
        // department so the bundle does not show fewer items than intended.
        $deptExcludeIds = array_merge(
            $excludeIds,
            array_map(static fn (Product $p): int => (int) $p->getId(), $sellerProducts)
        );

        return array_merge(
            $sellerProducts,
            $this->getAutoFillByDepartment($product, $deptExcludeIds, $stillRemaining)
        );
    }

    /**
     * Pulls candidates from the product's department (top-level category under
     * the store root, via RelatedCarouselDataProvider::getProductsByDepartment),
     * filters out $excludeIds, then gates them through loadValidProducts().
     *
     * @param int[] $excludeIds
     * @return Product[]
     */
    private function getAutoFillByDepartment(Product $product, array $excludeIds, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        // Over-fetch from the department to give loadValidProducts() enough
        // candidates after the exclude filter removes already-chosen ids.
        $rawDept = $this->relatedCarouselDataProvider->getProductsByDepartment(
            $product,
            $limit + count($excludeIds)
        );

        $deptIds = array_values(array_diff(
            array_map(static fn (Product $p): int => (int) $p->getId(), $rawDept),
            $excludeIds
        ));

        return $this->loadValidProducts($deptIds, $excludeIds, $limit);
    }

    /**
     * SKU-pattern check for "sourced from a distributor" - mirrors the
     * identical check in CustomersAlsoBought::isDistributorProduct() so both
     * sections route the same products the same way.
     *
     * These SKUs are typically routed through the "Everest Marketplace"
     * placeholder seller (CWR / Bill Hicks feeds) and are not real
     * merchandising sellers, so the seller-catalog tier is skipped for them.
     */
    private function isDistributorProduct(Product $product): bool
    {
        $sku = strtolower((string) $product->getSku());

        if (str_starts_with($sku, 'cwr-')) {
            return true;
        }

        return str_starts_with($sku, 'bil-') && str_ends_with($sku, '-1');
    }

    /**
     * Loads the given ids, keeping only products the section can actually offer,
     * and preserving the order the ids arrived in.
     *
     * Simple and configurable only. A configurable suggestion is not one-click
     * addable - the section's add flow now opens a swatch-selection modal for
     * it (see frequently-bought-together.phtml), which needs a real
     * super_attribute set to render. Bundle/grouped/downloadable/virtual still
     * have no such flow, so they stay excluded here rather than reaching the
     * template and failing silently at add-to-cart time.
     *
     * @param int[] $ids
     * @param int[] $excludedIds
     * @return Product[]
     */
    private function loadValidProducts(array $ids, array $excludedIds, int $limit): array
    {
        $ids = array_values(array_diff(array_unique(array_map('intval', $ids)), $excludedIds));

        if (!$ids || $limit < 1) {
            return [];
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'price', 'special_price', 'image', 'small_image'])
            ->addAttributeToFilter('entity_id', ['in' => $ids])
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', ['neq' => Visibility::VISIBILITY_NOT_VISIBLE])
            ->addAttributeToFilter('type_id', ['in' => [ProductType::TYPE_SIMPLE, Configurable::TYPE_CODE]]);

        $byId = [];
        foreach ($collection as $candidate) {
            $byId[(int) $candidate->getId()] = $candidate;
        }

        $valid = [];
        // Iterate $ids, not the collection: the ranking is in the id order and a
        // collection does not preserve it.
        foreach ($ids as $id) {
            if (count($valid) >= $limit) {
                break;
            }

            $candidate = $byId[$id] ?? null;
            if ($candidate === null) {
                continue;
            }

            try {
                // Enabled/visible already filtered at the collection level above;
                // isSalable() additionally covers stock and website assignment.
                if (!$this->isCandidateSalable($candidate)) {
                    continue;
                }

                if ($this->resolveStockQty($candidate) <= self::MIN_STOCK_QTY) {
                    continue;
                }

                $finalPrice = $this->resolvePrice($candidate);
                if ($finalPrice <= 0 || $finalPrice >= self::MAX_SUGGESTION_PRICE) {
                    continue;
                }

                if (!$this->hasSeller($id)) {
                    continue;
                }
            } catch (\Throwable $exception) {
                continue;
            }

            $valid[] = $candidate;
        }

        return $valid;
    }

    /**
     * Whether the section can offer this candidate at all.
     *
     * A configurable carries no stock of its own: its
     * cataloginventory_stock_item row is qty 0 / is_in_stock 0, and with MSI
     * disabled on this install isSalable() reads that row directly, so a
     * parent whose variants are all in stock still answers false. Fall back to
     * the variants in that case - the modal makes the shopper pick one anyway,
     * so a parent is offerable exactly when some variant is.
     */
    private function isCandidateSalable(Product $candidate): bool
    {
        if ($candidate->isSalable()) {
            return true;
        }

        return $this->getSalableVariants($candidate) !== [];
    }

    /**
     * The stock figure the MIN_STOCK_QTY gate should judge this candidate on.
     *
     * For a configurable that is the deepest single variant, not the sum: the
     * shopper buys one variant, so the gate's promise - that the suggestion
     * will not sell out mid-checkout - only holds if some individual variant
     * clears the bar on its own. Ten variants holding one unit each is still
     * ten one-unit variants.
     */
    private function resolveStockQty(Product $candidate): float
    {
        $ownQty = (float) $this->stockRegistry->getStockItem((int) $candidate->getId())->getQty();
        if ($ownQty > 0 || $candidate->getTypeId() !== Configurable::TYPE_CODE) {
            return $ownQty;
        }

        $best = 0.0;
        foreach ($this->getSalableVariants($candidate) as $variant) {
            $qty = (float) $this->stockRegistry->getStockItem((int) $variant->getId())->getQty();
            if ($qty > $best) {
                $best = $qty;
            }
        }

        return $best;
    }

    /**
     * The price the MAX_SUGGESTION_PRICE gate should judge this candidate on.
     *
     * A configurable has no price row of its own, and getPriceInfo() on the
     * parent can return an uninitialised figure - measured 100058 on
     * hytrek-packable-pants-black, whose variants are ~$100, while the price
     * index reported 0.00 for the same product. Either value fails the gate, so
     * before this every configurable was rejected regardless of its real price.
     *
     * Judged on the cheapest salable variant, matching resolveStockQty()'s
     * reasoning: the shopper buys one variant, so the gate has to be about a
     * variant they can actually buy. Cheapest rather than dearest so one
     * expensive size cannot push an otherwise eligible product over the ceiling.
     */
    private function resolvePrice(Product $candidate): float
    {
        if ($candidate->getTypeId() !== Configurable::TYPE_CODE) {
            return (float) $candidate->getPriceInfo()
                ->getPrice('final_price')->getAmount()->getValue();
        }
        $best = 0.0;
        foreach ($this->getSalableVariants($candidate) as $variant) {
            $price = (float) $variant->getPriceInfo()
                ->getPrice('final_price')->getAmount()->getValue();
            if ($price > 0 && ($best === 0.0 || $price < $best)) {
                $best = $price;
            }
        }
        return $best;
    }

    /**
     * Enabled, salable variants of a configurable; empty for anything else.
     *
     * Enabled is checked explicitly rather than left to isSalable(): the
     * FlxPoint import writes children straight to catalog_product_entity_int
     * with status 0, and those must not resurrect a parent.
     *
     * @return Product[]
     */
    private function getSalableVariants(Product $candidate): array
    {
        $id = (int) $candidate->getId();
        if (isset($this->salableVariants[$id])) {
            return $this->salableVariants[$id];
        }

        $variants = [];
        if ($candidate->getTypeId() === Configurable::TYPE_CODE) {
            try {
                foreach ($this->configurableType->getUsedProducts($candidate) as $variant) {
                    if ((int) $variant->getStatus() !== Status::STATUS_ENABLED) {
                        continue;
                    }

                    if ($variant->isSalable()) {
                        $variants[] = $variant;
                    }
                }
            } catch (\Throwable $exception) {
                $this->logger->warning(
                    'FBT: could not load variants for configurable ' . $id . ': ' . $exception->getMessage()
                );
            }
        }

        return $this->salableVariants[$id] = $variants;
    }

    /**
     * Whether a marketplace seller is assigned to this product.
     *
     * The FBT card shows the seller's shop name (see
     * frequently-bought-together.phtml); a suggestion with no seller has
     * nothing to show there, so it is excluded rather than rendered blank.
     */
    private function hasSeller(int $productId): bool
    {
        $sellerData = $this->marketplaceHelper->getSellerProductDataByProductId($productId);
        $row = $sellerData->getData()[0] ?? null;

        return !empty($row) && !empty($row['seller_id']);
    }

    /**
     * Manually picked products, in the order the admin arranged them.
     *
     * Read straight from catalog_product_link rather than through the product's
     * link collection: this only needs ids in position order, and loading the
     * link models would hydrate products twice.
     *
     * @return int[]
     */
    private function getManualProductIds(Product $product): array
    {
        $connection = $this->resourceConnection->getConnection();
        $linkTable = $this->resourceConnection->getTableName('catalog_product_link');
        $attrIntTable = $this->resourceConnection->getTableName('catalog_product_link_attribute_int');

        $select = $connection->select()
            ->from(['l' => $linkTable], ['linked_product_id'])
            ->where('l.product_id = ?', (int) $product->getId())
            ->where('l.link_type_id = ?', CreateManualFbtLinkType::LINK_TYPE_MANUAL_FBT);

        $positionAttributeId = $this->getPositionAttributeId();
        if ($positionAttributeId !== null) {
            $select->joinLeft(
                ['p' => $attrIntTable],
                $connection->quoteInto(
                    'p.link_id = l.link_id AND p.product_link_attribute_id = ?',
                    $positionAttributeId
                ),
                []
            )->order('p.value ASC');
        }

        $select->order('l.link_id ASC');

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Id of the position attribute belonging to the manual-FBT link type, or null
     * when the data patch has not run on this instance.
     */
    private function getPositionAttributeId(): ?int
    {
        if ($this->positionAttributeId !== null) {
            return $this->positionAttributeId ?: null;
        }

        $connection = $this->resourceConnection->getConnection();
        $id = $connection->fetchOne(
            $connection->select()
                ->from(
                    $this->resourceConnection->getTableName('catalog_product_link_attribute'),
                    ['product_link_attribute_id']
                )
                ->where('link_type_id = ?', CreateManualFbtLinkType::LINK_TYPE_MANUAL_FBT)
                ->where('product_link_attribute_code = ?', 'position')
        );

        // 0 is the "looked up, absent" marker so a missing row is not re-queried
        // on every call.
        $this->positionAttributeId = $id === false || $id === null ? 0 : (int) $id;

        return $this->positionAttributeId ?: null;
    }

    /**
     * Configured SKUs resolved to ids.
     *
     * Exact matches only - never a LIKE. Over 200 products in this catalogue
     * contain "free" or "decal" in their SKU and most are ordinary paid
     * merchandise; the paid "Be Lost" decals in particular sit one character away
     * from the free one. A pattern match would remove real sellable products.
     *
     * A SKU that resolves to nothing is logged rather than ignored silently: SKUs
     * are editable, so a rename turns this setting into a no-op and the excluded
     * product quietly reappears in suggestions.
     *
     * @return int[]
     */
    private function getExcludedProductIds(): array
    {
        $configured = trim((string) $this->scopeConfig->getValue(
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
                ->from(
                    $this->resourceConnection->getTableName('catalog_product_entity'),
                    ['entity_id', 'sku']
                )
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
                '[PDPRevamp] FBT excluded_skus contains SKUs that match no product: '
                . implode(', ', $missing)
                . '. They are being ignored - check for a renamed product.'
            );
        }

        return $ids;
    }

    private function getMaxSuggestions(): int
    {
        $value = (int) $this->scopeConfig->getValue(
            self::XML_PATH_MAX_SUGGESTIONS,
            ScopeInterface::SCOPE_STORE
        );

        return $value > 0 ? $value : self::DEFAULT_MAX_SUGGESTIONS;
    }

    private function getMinCoPurchaseOrders(): ?int
    {
        $value = (int) $this->scopeConfig->getValue(
            self::XML_PATH_MIN_COPURCHASE,
            ScopeInterface::SCOPE_STORE
        );

        return $value > 0 ? $value : null;
    }
}
