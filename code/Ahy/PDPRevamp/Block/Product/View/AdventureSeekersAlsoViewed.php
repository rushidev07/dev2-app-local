<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Block\Product\View;

use Ahy\PDPRevamp\Model\Product\RelatedCarouselDataProvider;
use Ahy\PDPRevamp\Model\ResourceModel\ViewAffinity;
use Ahy\PDPRevamp\Setup\Patch\Data\CreateManualCarouselLinkTypes;
use Hyva\Theme\Model\ViewModelRegistry;
use Hyva\Theme\ViewModel\CurrentProduct;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Webkul\Marketplace\Helper\Data as MarketplaceHelper;
use Webkul\Marketplace\Model\ResourceModel\Seller\CollectionFactory as MpSellerCollectionFactory;

/**
 * Badge overrides: when an admin hasn't entered a value in Stores >
 * Configuration > PDP Badges, each badge falls back to its original
 * automatic behavior. Best Seller falls back to Magento's own sales
 * bestsellers report; Under $X falls back to flagging the 2nd-cheapest
 * item in this carousel. Setting a seller / a dollar value in config
 * overrides that automatic behavior for as long as the value is set.
 *
 * Group lookup, bestseller/seller-id/review-summary batching, and the
 * brand-fallback label are shared with CustomersAlsoBought via
 * RelatedCarouselDataProvider - see that class for the reasoning.
 */
class AdventureSeekersAlsoViewed extends Template
{
    public const GROUP_NAME = 'Adventure Seekers Also Viewed';

    /** Matches the old Amasty group's max_products setting - the display cap. */
    private const MAX_PRODUCTS = 10;

    /**
     * How many department candidates to pull before the price/stock/seller
     * filters below run. Wider than MAX_PRODUCTS so those filters have room
     * to reject some candidates without starving the carousel down to a
     * handful of items.
     */
    private const CANDIDATE_POOL_SIZE = 40;

    private const XML_PATH_BEST_SELLER_ENABLED = 'pdprevamp_pdp_badges/adventure_seekers/best_seller_enabled';
    private const XML_PATH_BEST_SELLER_NAME = 'pdprevamp_pdp_badges/adventure_seekers/best_seller_name';
    private const XML_PATH_TOP_RATED_ENABLED = 'pdprevamp_pdp_badges/adventure_seekers/top_rated_enabled';
    private const XML_PATH_TOP_RATED_THRESHOLD = 'pdprevamp_pdp_badges/adventure_seekers/top_rated_threshold';
    private const XML_PATH_SAME_SELLER_ENABLED = 'pdprevamp_pdp_badges/adventure_seekers/same_seller_enabled';
    private const XML_PATH_UNDER_PRICE_ENABLED = 'pdprevamp_pdp_badges/adventure_seekers/under_price_enabled';
    private const XML_PATH_UNDER_PRICE_VALUE = 'pdprevamp_pdp_badges/adventure_seekers/under_price_value';

    private const XML_PATH_VIEWED_BOOST_ENABLED = 'pdprevamp_asav/general/viewed_boost_enabled';
    private const XML_PATH_VIEWED_LOOKBACK_DAYS = 'pdprevamp_asav/general/viewed_lookback_days';
    private const DEFAULT_VIEWED_LOOKBACK_DAYS = 90;

    private RelatedCarouselDataProvider $dataProvider;
    private ViewModelRegistry $viewModelRegistry;
    private ImageHelper $imageHelper;
    private MpSellerCollectionFactory $mpSellerCollectionFactory;
    private MarketplaceHelper $marketplaceHelper;
    private ViewAffinity $viewAffinity;
    private StoreManagerInterface $storeManager;

    /** @var array|null */
    private $items;

    public function __construct(
        Context $context,
        RelatedCarouselDataProvider $dataProvider,
        ViewModelRegistry $viewModelRegistry,
        ImageHelper $imageHelper,
        MpSellerCollectionFactory $mpSellerCollectionFactory,
        MarketplaceHelper $marketplaceHelper,
        ViewAffinity $viewAffinity,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->dataProvider = $dataProvider;
        $this->viewModelRegistry = $viewModelRegistry;
        $this->imageHelper = $imageHelper;
        $this->mpSellerCollectionFactory = $mpSellerCollectionFactory;
        $this->marketplaceHelper = $marketplaceHelper;
        $this->viewAffinity = $viewAffinity;
        $this->storeManager = $storeManager;
    }

    private function isViewedBoostEnabled(): bool
    {
        return $this->_scopeConfig->isSetFlag(self::XML_PATH_VIEWED_BOOST_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    private function getViewedLookbackDays(): int
    {
        $value = (int) $this->_scopeConfig->getValue(self::XML_PATH_VIEWED_LOOKBACK_DAYS, ScopeInterface::SCOPE_STORE);
        return $value > 0 ? $value : self::DEFAULT_VIEWED_LOOKBACK_DAYS;
    }

    private function isBestSellerEnabled(): bool
    {
        return $this->_scopeConfig->isSetFlag(self::XML_PATH_BEST_SELLER_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    private function getBestSellerName(): ?string
    {
        $value = trim((string) $this->_scopeConfig->getValue(self::XML_PATH_BEST_SELLER_NAME, ScopeInterface::SCOPE_STORE));
        return $value !== '' ? $value : null;
    }

    /**
     * Resolves the admin-picked seller name (Stores > Configuration > AHY >
     * PDP Badges - a dropdown of real sellers, see Model\Config\Source\
     * SellerNames) to the marketplace seller entity id it matches, so the
     * same id-based comparison used everywhere else in this class still
     * works. marketplace_userdata has no "name" column - shop_title is the
     * only real display-name field on that table - so matching is
     * case-insensitive against shop_title only. Null when nothing matches
     * (falls back to the automatic bestseller behavior, same as an empty
     * field).
     */
    private function resolveSellerIdByName(string $name): ?int
    {
        $collection = $this->mpSellerCollectionFactory->create();
        $collection->addFieldToFilter('shop_title', ['eq' => $name]);

        foreach ($collection as $seller) {
            $shopTitle = trim((string) $seller->getData('shop_title'));
            if (strcasecmp($shopTitle, $name) === 0) {
                return (int) $seller->getData('entity_id');
            }
        }

        return null;
    }

    private function isTopRatedEnabled(): bool
    {
        return $this->_scopeConfig->isSetFlag(self::XML_PATH_TOP_RATED_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    private function getTopRatedThreshold(): float
    {
        $value = (float) $this->_scopeConfig->getValue(self::XML_PATH_TOP_RATED_THRESHOLD, ScopeInterface::SCOPE_STORE);
        return $value > 0 ? $value : 90.0;
    }

    private function isSameSellerEnabled(): bool
    {
        return $this->_scopeConfig->isSetFlag(self::XML_PATH_SAME_SELLER_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    private function isUnderPriceEnabled(): bool
    {
        return $this->_scopeConfig->isSetFlag(self::XML_PATH_UNDER_PRICE_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Null when an admin hasn't entered a value - the caller should then
     * fall back to the automatic "2nd-cheapest item" behavior instead of a
     * fixed dollar threshold.
     */
    private function getUnderPriceValue(): ?float
    {
        $value = $this->_scopeConfig->getValue(self::XML_PATH_UNDER_PRICE_VALUE, ScopeInterface::SCOPE_STORE);
        if ($value === null || $value === '') {
            return null;
        }
        $floatValue = (float) $value;
        return $floatValue > 0 ? $floatValue : null;
    }

    /**
     * "Under $5" for a whole-number threshold, "Under $4.99" for a
     * fractional one - avoids an admin-entered "5" rendering as "Under $5.00".
     */
    private function formatUnderPriceLabel(float $value): string
    {
        $formatted = fmod($value, 1.0) === 0.0 ? (string) (int) $value : number_format($value, 2);
        return (string) __('Under $%1', $formatted);
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
     * ProductBrands controller) - rather than a second hand-rolled
     * marketplace_userdata query that can drift out of sync with the real
     * schema (that's what caused this method to filter on the wrong column
     * before). Falls back to the seller's plain name, unlike
     * CustomersAlsoBought's own version which only trusts shop_title -
     * unifying them would change one block's behavior.
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

    private function getProducts(): array
    {
        $currentProduct = $this->getCurrentProduct();

        // Manual admin override always wins outright when set - matches
        // CustomersAlsoBought's priority (see that class's getProducts()).
        // A merchandiser's explicit pick for this grid always beats the
        // automatic department-based selection below.
        $manualProducts = $this->dataProvider->getManualFallbackProducts(
            $currentProduct,
            CreateManualCarouselLinkTypes::LINK_TYPE_MANUAL_ASAV
        );
        if ($manualProducts) {
            return $manualProducts;
        }

        $departmentProducts = $this->dataProvider->getProductsByDepartment($currentProduct, self::CANDIDATE_POOL_SIZE);

        $remaining = self::CANDIDATE_POOL_SIZE - count($departmentProducts);
        if ($remaining <= 0) {
            return $departmentProducts;
        }

        // The department alone didn't fill the pool (including the case
        // where it found nothing at all - e.g. a small category, or most of
        // its products failing the price/stock/seller filters in getItems()).
        $excludeIds = array_merge(
            [(int) $currentProduct->getId()],
            array_map(static fn (Product $p): int => (int) $p->getId(), $departmentProducts)
        );

        // Distributor-fed products (e.g. Bill Hicks / CWR via the "Everest
        // Marketplace" placeholder seller) skip this tier entirely and go
        // straight to the bestseller top-up below - that placeholder isn't
        // a real merchandising seller, so "more from this seller" would be
        // meaningless for it. Every other product tries same-seller first.
        $sellerProducts = [];
        if (!$this->dataProvider->isDistributorProduct($currentProduct)) {
            $sellerId = $this->dataProvider->getSellerIdsByProductIds([(int) $currentProduct->getId()])[(int) $currentProduct->getId()] ?? null;
            if ($sellerId !== null) {
                $sellerProducts = array_values(array_filter(
                    $this->dataProvider->getProductsBySeller($sellerId, (int) $currentProduct->getId(), $remaining + count($excludeIds)),
                    static fn (Product $p): bool => !in_array((int) $p->getId(), $excludeIds, true)
                ));
                $sellerProducts = array_slice($sellerProducts, 0, $remaining);
            }
        }

        $stillRemaining = $remaining - count($sellerProducts);
        $bestsellerProducts = [];
        if ($stillRemaining > 0) {
            $bestsellerExcludeIds = array_merge(
                $excludeIds,
                array_map(static fn (Product $p): int => (int) $p->getId(), $sellerProducts)
            );
            $bestsellerIds = array_values(array_diff(
                array_keys($this->dataProvider->getBestsellerProductIds()),
                $bestsellerExcludeIds
            ));
            $bestsellerProducts = $bestsellerIds
                ? $this->dataProvider->getProductsByIds($bestsellerIds, $stillRemaining)
                : [];
        }

        return array_merge($departmentProducts, $sellerProducts, $bestsellerProducts);
    }

    public function getItems(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }

        $products = $this->getProducts();
        $bestsellerIds = $this->dataProvider->getBestsellerProductIds();

        $productIds      = array_map(static fn (Product $p): int => (int) $p->getId(), $products);
        $reviewSummaries = $this->dataProvider->getReviewSummaryByProductIds($productIds);

        $currentProductId     = (int) $this->getCurrentProduct()->getId();
        $sellerIdsByProductId = $this->dataProvider->getSellerIdsByProductIds(array_merge(
            [$currentProductId],
            $productIds
        ));
        $currentSellerId       = $sellerIdsByProductId[$currentProductId] ?? null;
        $sellerNamesBySellerId = $this->getSellerNamesBySellerIds(array_values($sellerIdsByProductId));

        $bestSellerEnabled       = $this->isBestSellerEnabled();
        $bestSellerName          = $bestSellerEnabled ? $this->getBestSellerName() : null;
        $bestSellerSellerId      = $bestSellerName !== null ? $this->resolveSellerIdByName($bestSellerName) : null;
        $topRatedEnabled         = $this->isTopRatedEnabled();
        $topRatedThreshold       = $this->getTopRatedThreshold();
        $sameSellerEnabled       = $this->isSameSellerEnabled();
        $underPriceEnabled       = $this->isUnderPriceEnabled();
        $underPriceConfigValue   = $underPriceEnabled ? $this->getUnderPriceValue() : null;

        $rawItems = [];
        foreach ($products as $product) {
            /** @var Product $product */
            if (!$product->isSaleable()) {
                continue;
            }

            // Product must be enabled (Stores > Catalog > status = Enabled).
            if ((int) $product->getStatus() !== Status::STATUS_ENABLED) {
                continue;
            }

            $pid          = (int) $product->getId();

            $finalPrice   = (float) $product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
            $regularPrice = (float) $product->getPriceInfo()->getPrice('regular_price')->getAmount()->getValue();

            // Only show items priced under $999 in this carousel.
            if ($finalPrice >= 999.0) {
                continue;
            }

            // Only show items with more than 8 units in stock. Configurable-
            // aware: a configurable candidate's own stock item is normally
            // empty (real qty lives on its child variants), so this checks
            // any salable child instead of just reading the parent's own
            // record - see RelatedCarouselDataProvider::hasStockAbove().
            if (!$this->dataProvider->hasStockAbove($product, 8.0)) {
                continue;
            }

            $requiredOptions = (bool) $product->getTypeInstance()->hasRequiredOptions($product);

            $reviewData    = $reviewSummaries[$pid] ?? null;
            $ratingSummary = $reviewData ? $reviewData['rating'] : 0.0;
            $reviewsCount  = $reviewData ? $reviewData['count'] : 0;
            $sid           = $sellerIdsByProductId[$pid] ?? null;

            // Product must have a marketplace seller assigned.
            if ($sid === null) {
                continue;
            }

            // Best Seller: an admin-selected seller overrides Magento's own
            // sales bestsellers report; without one, fall back to it.
            $isBestseller = $bestSellerEnabled && ($bestSellerSellerId !== null
                ? $sid === $bestSellerSellerId
                : isset($bestsellerIds[$pid]));
            $isTopRated   = $topRatedEnabled && $ratingSummary >= $topRatedThreshold;
            $isSameSeller = $sameSellerEnabled && $currentSellerId !== null && $sid === $currentSellerId;

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
                'isConfigurable'  => $product->getTypeId() === 'configurable' && $product->isSaleable(),
                'addToCartUrl'    => $this->getUrl('checkout/cart/add', [
                    '_secure' => true,
                    'product' => $product->getId(),
                ]),
                'isBestseller'    => $isBestseller,
                'isTopRated'      => $isTopRated,
                'isSameSeller'    => $isSameSeller,
                'sellerName'      => $this->dataProvider->resolveDisplayBrand($product, $sid ? ($sellerNamesBySellerId[$sid] ?? null) : null),
            ];
        }

        // Boost by real "also viewed" data: within this already
        // department-scoped pool, items that shoppers who viewed the
        // current product actually went on to view too get ranked to the
        // front. Items with no view data keep the pool's existing
        // (randomised) relative order - usort is stable as of PHP 8, so
        // ties don't get reshuffled again here.
        if ($rawItems && $this->isViewedBoostEnabled()) {
            $viewCounts = $this->viewAffinity->getCoViewedCounts(
                $currentProductId,
                array_map(static fn (array $item): int => $item['id'], $rawItems),
                (int) $this->storeManager->getStore()->getId(),
                $this->getViewedLookbackDays()
            );

            if ($viewCounts) {
                usort(
                    $rawItems,
                    static fn (array $a, array $b): int
                        => ($viewCounts[$b['id']] ?? 0) <=> ($viewCounts[$a['id']] ?? 0)
                );
            }
        }

        $rawItems = array_slice($rawItems, 0, self::MAX_PRODUCTS);

        // Under $X: an admin-entered value flags every item priced below
        // it. Without one, fall back to flagging just the 2nd-cheapest
        // item in this carousel (the original automatic behavior).
        $underPriceTargetId = null;
        $underPriceAutoLabel = null;
        if ($underPriceEnabled && $underPriceConfigValue === null) {
            $sortedByPrice = $rawItems;
            usort($sortedByPrice, fn (array $a, array $b) => $a['finalPrice'] <=> $b['finalPrice']);
            if (isset($sortedByPrice[1])) {
                $underPriceTargetId = $sortedByPrice[1]['id'];
                $underPriceAutoLabel = $this->formatUnderPriceLabel((float) floor($sortedByPrice[1]['finalPrice']) + 1);
            }
        }

        $this->items = [];
        foreach ($rawItems as $item) {
            $isUnderPrice = false;
            $underPriceLabel = null;
            if ($underPriceEnabled) {
                if ($underPriceConfigValue !== null) {
                    $isUnderPrice = $item['finalPrice'] < $underPriceConfigValue;
                    $underPriceLabel = $this->formatUnderPriceLabel($underPriceConfigValue);
                } elseif ($item['id'] === $underPriceTargetId) {
                    $isUnderPrice = true;
                    $underPriceLabel = $underPriceAutoLabel;
                }
            }

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
            if ($isUnderPrice) {
                $badges[] = ['label' => $underPriceLabel, 'color' => '#2D7AF4'];
            }

            unset($item['isBestseller'], $item['isTopRated'], $item['isSameSeller']);
            $item['badges'] = $badges;
            $this->items[]  = $item;
        }

        return $this->items;
    }
}