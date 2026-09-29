<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin\SalesRule\Model;

use Ahy\PDPRevamp\Setup\Patch\Data\CreateFbtBundleDiscountAttribute;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\RulesApplier as RulesApplierSubject;
use Magento\SalesRule\Model\Validator;
use Magento\Store\Model\ScopeInterface;

/**
 * Applies the discount for a PDP "Frequently Bought Together" bundle add (see
 * view/frontend/templates/product/view/frequently-bought-together.phtml,
 * Plugin\Checkout\Model\Cart\TagFbtBundleItem and
 * Observer\RetagFbtBundleItem, which tag each line's quote_item_option with
 * ahy_fbt_bundle_id/ahy_fbt_bundle_size).
 *
 * The discount belongs to the BUNDLE, not to each line: the shopper was shown
 * one figure ("Bundle & Save 15%") against one total, so this computes
 * `bundle total x percent` once and then splits that single amount across the
 * bundle's lines, giving the rounding remainder to the largest line. Magento
 * has nowhere to store a discount except on individual quote items, so the
 * split is an implementation detail - what matters is that the lines add up to
 * exactly the advertised amount, which per-line rounding on its own does not
 * guarantee.
 *
 * Writes an ABSOLUTE amount (max of what is already on the line and this
 * bundle's share) rather than adding to whatever is there. The previous
 * version added, which made it dependent on being invoked exactly once per
 * totals pass - it was not, and a 3-item bundle at 15% was charging 45%
 * (3 x 15) because the same line was topped up on every invocation. An
 * absolute write is idempotent: run it once or ten times in a pass and the
 * line lands on the same number.
 *
 * max() also means an FBT bundle never stacks with a cart price rule - the
 * better of the two wins per line instead of both being summed. That is the
 * deliberate reversal of the old "always stacks on top of any other active
 * coupon/cart rule" behaviour, which quietly produced 35% off a bundle the
 * moment a 20% promo went live, and which also made the separate
 * Amasty\Mostviewed bundle-pack subtraction necessary. It is not needed now:
 * whatever Amasty put on the line is simply one of the two candidates.
 */
class RulesApplier
{
    private const OPTION_BUNDLE_ID = 'ahy_fbt_bundle_id';
    private const OPTION_BUNDLE_SIZE = 'ahy_fbt_bundle_size';

    /**
     * How many products a bundle must have before it is discounted at all.
     *
     * The offer is the full three-product bundle or nothing: a shopper who
     * unticks one of the three and adds two gets no discount, and a product
     * whose widget can only muster two products has no bundle offer to make.
     * Combined with the sibling count in getBundleAllocation(), which requires
     * every product the bundle was added with to still be in the cart, this is
     * what makes the discount all-or-nothing.
     */
    private const MIN_BUNDLE_ITEMS = 3;

    /**
     * Matches Ahy\PDPRevamp\Block\Product\View\FrequentlyBoughtTogether::
     * XML_PATH_DISCOUNT_PERCENT (private there, so duplicated here) - the same
     * value the PDP template shows via getBundleDiscountPercent(). The two
     * must agree or the PDP advertises a saving the checkout never applies.
     */
    private const XML_PATH_DISCOUNT_PERCENT = 'pdprevamp_fbt/general/discount_percent';

    private Validator $validator;
    private ScopeConfigInterface $scopeConfig;
    private ProductResource $productResource;

    /**
     * Resolved percent per bundle id, for this request only. afterApplyRules()
     * runs once per quote item and every item of a bundle resolves the same
     * anchor, so without this the same attribute is re-read for each of them,
     * on every totals collection.
     *
     * @var array<string, float>
     */
    private array $percentByBundleId = [];

    /**
     * Computed splits, keyed by a signature of the bundle's own line totals
     * rather than by bundle id alone - a qty change or a price change has to
     * produce a fresh split, and keying on the inputs makes that automatic
     * instead of something a cache-invalidation call has to remember to do.
     *
     * @var array<string, array<string, array{0: float, 1: float}>>
     */
    private array $allocationBySignature = [];

    public function __construct(
        Validator $validator,
        ScopeConfigInterface $scopeConfig,
        ProductResource $productResource
    ) {
        $this->validator = $validator;
        $this->scopeConfig = $scopeConfig;
        $this->productResource = $productResource;
    }

    /**
     * @param mixed $rules
     * @param mixed $skipValidation
     * @param mixed $couponCode
     */
    public function afterApplyRules(
        RulesApplierSubject $subject,
        array $appliedRuleIds,
        $item = null,
        $rules = null,
        $skipValidation = null,
        $couponCode = null
    ): array {
        if (!$item instanceof AbstractItem) {
            return $appliedRuleIds;
        }

        $bundleIdOption = $item->getOptionByCode(self::OPTION_BUNDLE_ID);
        $bundleSizeOption = $item->getOptionByCode(self::OPTION_BUNDLE_SIZE);
        if (!$bundleIdOption || !$bundleSizeOption) {
            return $appliedRuleIds;
        }

        $bundleId = trim((string) $bundleIdOption->getValue());
        $bundleSize = (int) $bundleSizeOption->getValue();
        if ($bundleId === '' || $bundleSize < self::MIN_BUNDLE_ITEMS) {
            return $appliedRuleIds;
        }

        $allocation = $this->getBundleAllocation($item, $bundleId, $bundleSize);
        $key = $this->itemKey($item);
        if (!isset($allocation[$key])) {
            return $appliedRuleIds;
        }

        [$discount, $baseDiscount] = $allocation[$key];

        $item->setDiscountAmount(max((float) $item->getDiscountAmount(), $discount));
        $item->setBaseDiscountAmount(max((float) $item->getBaseDiscountAmount(), $baseDiscount));

        return $appliedRuleIds;
    }

    /**
     * This bundle's discount, already split across its lines, as
     * [item key => [discount, base discount]].
     *
     * Returns [] - meaning no discount for anyone - unless every product the
     * bundle was added with is still in the cart. The shopper was offered a
     * price for buying these products together, so removing one from the cart
     * page ends the offer for what is left, and counting the siblings here is
     * what enforces that with no extra bookkeeping. A line still carrying the
     * tag of an older bundle falls out the same way, which is why a stale tag
     * now costs nothing (it used to be silently fatal to the discount - see
     * Observer\RetagFbtBundleItem).
     *
     * @return array<string, array{0: float, 1: float}>
     */
    private function getBundleAllocation(AbstractItem $item, string $bundleId, int $bundleSize): array
    {
        $quote = $item->getQuote();
        if (!$quote) {
            return [];
        }

        $rows = [];
        $baseRows = [];
        foreach ($quote->getAllVisibleItems() as $quoteItem) {
            $option = $quoteItem->getOptionByCode(self::OPTION_BUNDLE_ID);
            if (!$option || trim((string) $option->getValue()) !== $bundleId) {
                continue;
            }

            $key = $this->itemKey($quoteItem);
            $qty = (float) $quoteItem->getTotalQty();
            $rows[$key] = $qty * (float) $this->validator->getItemPrice($quoteItem);
            $baseRows[$key] = $qty * (float) $this->validator->getItemBasePrice($quoteItem);
        }

        if (count($rows) < max($bundleSize, self::MIN_BUNDLE_ITEMS)) {
            return [];
        }

        $signature = $bundleId . '|' . md5((string) json_encode([$rows, $baseRows]));
        if (isset($this->allocationBySignature[$signature])) {
            return $this->allocationBySignature[$signature];
        }

        $percent = $this->getBundleDiscountPercent($item, $bundleId);
        $bundleTotal = array_sum($rows);
        $baseBundleTotal = array_sum($baseRows);
        if ($percent <= 0 || $bundleTotal <= 0) {
            return $this->allocationBySignature[$signature] = [];
        }

        $shares = $this->split($rows, min(round($bundleTotal * $percent / 100, 2), $bundleTotal));
        $baseShares = $this->split($baseRows, min(round($baseBundleTotal * $percent / 100, 2), $baseBundleTotal));

        $allocation = [];
        foreach ($rows as $key => $row) {
            $allocation[$key] = [
                min(max($shares[$key] ?? 0.0, 0.0), $row),
                min(max($baseShares[$key] ?? 0.0, 0.0), $baseRows[$key] ?? 0.0),
            ];
        }

        return $this->allocationBySignature[$signature] = $allocation;
    }

    /**
     * Splits one bundle-level amount across the bundle's lines in proportion
     * to what each line contributes, to the cent.
     *
     * Every line but the largest is rounded normally and the largest takes
     * whatever is left, so the parts always sum to exactly $total - rounding
     * each line independently does not (3 lines of 15% off 6.99/74.95/44.00
     * round to 1.05 + 11.24 + 6.60 = 18.89 while 15% of the total is 18.89
     * here, but a cent of drift either way is routine). The largest line
     * absorbs the remainder because a cent on the biggest number is the least
     * conspicuous place to put it.
     *
     * @param array<string, float> $rows
     * @return array<string, float>
     */
    private function split(array $rows, float $total): array
    {
        $sum = array_sum($rows);
        if ($sum <= 0 || $total <= 0) {
            return array_fill_keys(array_keys($rows), 0.0);
        }

        $largestKey = null;
        $largest = -1.0;
        foreach ($rows as $key => $row) {
            if ($row > $largest) {
                $largest = $row;
                $largestKey = $key;
            }
        }

        if ($largestKey === null) {
            return array_fill_keys(array_keys($rows), 0.0);
        }

        $shares = [];
        $allocated = 0.0;
        foreach ($rows as $key => $row) {
            if ($key === $largestKey) {
                continue;
            }
            $share = round($total * $row / $sum, 2);
            $shares[$key] = $share;
            $allocated += $share;
        }

        $shares[$largestKey] = round($total - $allocated, 2);

        return $shares;
    }

    /**
     * A stable per-line key. Quote items being added in this very request have
     * no id yet, so fall back to the object handle - within one request the
     * same line is always the same object, which is all this needs. Prefixed
     * so the keys stay strings and PHP does not silently cast numeric ids to
     * ints, which would break the === comparisons in split().
     */
    private function itemKey(AbstractItem $item): string
    {
        $id = $item->getId();

        return $id ? 'i' . $id : 'o' . spl_object_id($item);
    }

    /**
     * Mirrors FrequentlyBoughtTogether::getBundleDiscountPercent() exactly -
     * per-product override first, store-wide config only as the fallback,
     * same 0-100 clamp - scoped to the item's own store rather than the
     * current request's store, since this runs during totals collection,
     * which isn't guaranteed to share a frontend request's store context
     * (e.g. admin order edit, cron).
     *
     * Reading only the config here (as this did originally) meant a product
     * with its own pdp_fbt_bundle_discount_percent advertised that percent on
     * the PDP and then got the store-wide one in the cart - and since the
     * shipped default for pdprevamp_fbt/general/discount_percent is 0, the
     * usual result was a bundle promising a discount and charging full price.
     *
     * The percent belongs to the bundle, not to each line: $bundleId is the
     * anchor product ("This Item" on the PDP, items[0] in the template), so
     * every item added with it is discounted by the anchor's own percent -
     * the exact figure the shopper was shown. Float, not int, because the
     * attribute is decimal and 12.5 is a legitimate percent.
     */
    private function getBundleDiscountPercent(AbstractItem $item, string $bundleId): float
    {
        if (isset($this->percentByBundleId[$bundleId])) {
            return $this->percentByBundleId[$bundleId];
        }

        // getAttributeRawValue() rather than loading the product: one small
        // query, no full model, no events - this is on the totals path.
        $override = $this->productResource->getAttributeRawValue(
            (int) $bundleId,
            CreateFbtBundleDiscountAttribute::ATTRIBUTE_CODE,
            (int) $item->getStoreId()
        );

        // Its return shape varies: the raw value when there is one, but an
        // empty array when there isn't (not null/false), and an array keyed by
        // code in some paths. Casting any of those straight to float is a trap
        // - (float) [] is 0.0, but (float) ['...' => '12'] is 1.0 - so unwrap
        // first and let is_numeric() decide whether an override really exists.
        if (is_array($override)) {
            $override = $override[CreateFbtBundleDiscountAttribute::ATTRIBUTE_CODE] ?? null;
        }

        $percent = is_numeric($override)
            ? (float) $override
            : (float) $this->scopeConfig->getValue(
                self::XML_PATH_DISCOUNT_PERCENT,
                ScopeInterface::SCOPE_STORE,
                $item->getStoreId()
            );

        if ($percent < 0) {
            $percent = 0.0;
        } elseif ($percent > 100) {
            $percent = 100.0;
        }

        return $this->percentByBundleId[$bundleId] = $percent;
    }
}
