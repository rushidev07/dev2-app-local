<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Block\Product\View;

use Ahy\PDPRevamp\Service\FbtSuggestionProvider;
use Ahy\PDPRevamp\Setup\Patch\Data\CreateFbtBundleDiscountAttribute;
use Hyva\Theme\Model\ViewModelRegistry;
use Hyva\Theme\ViewModel\CurrentProduct;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Pricing\Helper\Data as PricingHelper;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;

class FrequentlyBoughtTogether extends Template
{
    private const XML_PATH_ENABLED = 'pdprevamp_fbt/general/enabled';
    private const XML_PATH_HEADING = 'pdprevamp_fbt/general/heading';
    private const XML_PATH_DISCOUNT_PERCENT = 'pdprevamp_fbt/general/discount_percent';

    private ViewModelRegistry $viewModelRegistry;
    private ImageHelper $imageHelper;
    private PricingHelper $priceHelper;
    private FbtSuggestionProvider $suggestionProvider;
    private ProductResource $productResource;

    /** @var Product[]|null */
    private ?array $suggestions = null;

    public function __construct(
        Context $context,
        ViewModelRegistry $viewModelRegistry,
        ImageHelper $imageHelper,
        PricingHelper $priceHelper,
        FbtSuggestionProvider $suggestionProvider,
        ProductResource $productResource,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->viewModelRegistry = $viewModelRegistry;
        $this->imageHelper = $imageHelper;
        $this->priceHelper = $priceHelper;
        $this->suggestionProvider = $suggestionProvider;
        $this->productResource = $productResource;
    }

    public function getCurrentProduct(): Product
    {
        /** @var CurrentProduct $currentProduct */
        $currentProduct = $this->viewModelRegistry->require(CurrentProduct::class);
        return $currentProduct->get();
    }

    public function getImageHelper(): ImageHelper
    {
        return $this->imageHelper;
    }

    public function getPriceHelper(): PricingHelper
    {
        return $this->priceHelper;
    }

    /**
     * Whether the section should render at all.
     *
     * Requires the master switch AND at least one suggestion. A section headed
     * "Frequently Bought Together" with nothing beside the current product is
     * worse than no section, so the template checks this before emitting anything.
     */
    public function isEnabled(): bool
    {
        if (!$this->_scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)) {
            return false;
        }

        return $this->getSuggestedProducts() !== [];
    }

    public function getHeading(): string
    {
        $heading = trim((string) $this->_scopeConfig->getValue(
            self::XML_PATH_HEADING,
            ScopeInterface::SCOPE_STORE
        ));

        return $heading !== '' ? $heading : (string) __('Frequently Bought Together');
    }

    /**
     * @return Product[]
     */
    public function getSuggestedProducts(): array
    {
        // Memoised: the template asks for these several times per render (count,
        // the cards, the bundle total), and the provider runs an aggregate query
        // over sales_order_item.
        if ($this->suggestions === null) {
            $this->suggestions = $this->suggestionProvider->getSuggestions($this->getCurrentProduct());
        }

        return $this->suggestions;
    }

    /**
     * All bundle items: the current product first, then suggestions.
     *
     * @return Product[]
     */
    public function getBundleItems(): array
    {
        return array_merge([$this->getCurrentProduct()], $this->getSuggestedProducts());
    }

    /**
     * The store-wide discount percent (pdprevamp_fbt/general/discount_percent),
     * unless THIS product carries its own pdp_fbt_bundle_discount_percent -
     * see Setup\Patch\Data\CreateFbtBundleDiscountAttribute - in which case
     * that per-bundle value wins. Empty/null on the product (the default)
     * means "no override", not "zero"; an explicit 0 on the product is
     * honoured as "no discount for this bundle specifically".
     *
     * Returned as float, not int: a 12.5 override would otherwise truncate to
     * 12, and the SalesRule plugin that actually charges the discount reads the
     * same value as a float - the two must agree or the PDP advertises a
     * saving the checkout never applies.
     */
    public function getBundleDiscountPercent(): float
    {
        $product = $this->getCurrentProduct();
        $productValue = $product->getData(CreateFbtBundleDiscountAttribute::ATTRIBUTE_CODE);

        // The product handed over by Hyva's CurrentProduct is not guaranteed to
        // carry every custom attribute: which ones are hydrated depends on how
        // the product was loaded upstream, and a value saved in admin was
        // reaching the DB but arriving here as null, so the override was
        // silently ignored and the store-wide default used instead.
        // Re-read it straight from the attribute table in that case.
        if (!is_numeric($productValue)) {
            $productValue = $this->readRawDiscountPercent((int) $product->getId());
        }

        $percent = is_numeric($productValue)
            ? (float) $productValue
            : (float) $this->_scopeConfig->getValue(
                self::XML_PATH_DISCOUNT_PERCENT,
                ScopeInterface::SCOPE_STORE
            );

        if ($percent < 0) {
            return 0.0;
        }

        return $percent > 100 ? 100.0 : $percent;
    }

    /**
     * The override read straight from the attribute table, for when the loaded
     * product does not carry it.
     *
     * getAttributeRawValue() does not return null when there is no value - it
     * returns an empty array, and in some paths an array keyed by attribute
     * code - so unwrap before testing. Returns null when there is genuinely no
     * override, which is what makes the caller fall through to store config.
     *
     * @return string|float|int|null
     */
    private function readRawDiscountPercent(int $productId)
    {
        if ($productId < 1) {
            return null;
        }

        try {
            $raw = $this->productResource->getAttributeRawValue(
                $productId,
                CreateFbtBundleDiscountAttribute::ATTRIBUTE_CODE,
                (int) $this->_storeManager->getStore()->getId()
            );
        } catch (\Throwable $exception) {
            return null;
        }

        if (is_array($raw)) {
            $raw = $raw[CreateFbtBundleDiscountAttribute::ATTRIBUTE_CODE]
                ?? (count($raw) === 1 ? reset($raw) : null);
        }

        return is_numeric($raw) ? $raw : null;
    }

    /** @var array<int, Product|null> */
    private array $cheapestVariantCache = [];

    public function getItemFinalPrice(Product $item): float
    {
        return $this->resolveItemPrice($item, 'final_price');
    }
    public function getItemRegularPrice(Product $item): float
    {
        return $this->resolveItemPrice($item, 'regular_price');
    }
    /**
     * A configurable's own price info is not reliable for display - the parent
     * carries no price row and can report an uninitialised figure, which rendered
     * as "$100058" on the card. Falls back to the cheapest salable variant, which
     * is the "from" price a shopper expects for a product with size/colour
     * options, and is the same figure the selection gate admits the product on
     * (Service\FbtSuggestionProvider::resolvePrice()).
     *
     * Both the final and regular price shown on one card must come from the
     * SAME variant. This used to run two independent loops - "cheapest
     * final_price variant" for one call, "cheapest regular_price variant" for
     * the other - and whenever those turned out to be two different variants
     * (e.g. a hoodie where the Small has the lowest regular price but the XL
     * is the one on clearance), the card showed a "was $55 / now $44" that no
     * single buyable variant actually offers: final price from one variant,
     * struck-through regular price from a different one. The cart never shows
     * this because it always prices the one specific variant the shopper
     * picked in the modal - which is what made this look like a "PDP wrong,
     * cart right" bug rather than a bad discount calculation.
     *
     * getCheapestVariant() now settles on ONE variant per product (lowest
     * final_price) and both prices are read off of it.
     *
     * Falls back to the parent's own value when no variant yields a price, so a
     * simple product - or a configurable whose variants cannot be loaded - keeps
     * exactly the behaviour it had before.
     */
    private function resolveItemPrice(Product $item, string $priceCode): float
    {
        $own = (float) $item->getPriceInfo()->getPrice($priceCode)->getAmount()->getValue();
        if ($item->getTypeId() !== Configurable::TYPE_CODE) {
            return $own;
        }

        $variant = $this->getCheapestVariant($item);
        if ($variant === null) {
            return $own;
        }

        try {
            $price = (float) $variant->getPriceInfo()->getPrice($priceCode)->getAmount()->getValue();
        } catch (\Throwable $exception) {
            return $own;
        }

        return $price > 0.0 ? $price : $own;
    }

    /**
     * The single variant both getItemFinalPrice() and getItemRegularPrice()
     * read from for a given configurable - chosen once (lowest final_price)
     * and memoised per product id, since the template asks for both prices on
     * every card.
     */
    private function getCheapestVariant(Product $item): ?Product
    {
        $productId = (int) $item->getId();
        if (array_key_exists($productId, $this->cheapestVariantCache)) {
            return $this->cheapestVariantCache[$productId];
        }

        $best = null;
        $bestPrice = 0.0;
        try {
            foreach ($item->getTypeInstance()->getUsedProducts($item) as $variant) {
                try {
                    $price = (float) $variant->getPriceInfo()
                        ->getPrice('final_price')->getAmount()->getValue();
                } catch (\Throwable $exception) {
                    continue;
                }
                if ($price > 0 && ($best === null || $price < $bestPrice)) {
                    $best = $variant;
                    $bestPrice = $price;
                }
            }
        } catch (\Throwable $exception) {
            $best = null;
        }

        return $this->cheapestVariantCache[$productId] = $best;
    }
}
