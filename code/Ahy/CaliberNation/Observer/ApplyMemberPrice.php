<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Observer;

use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Service\MemberAccess;
use Ahy\CaliberNation\Model\Service\Pricing\MemberPriceResolver;
use Ahy\CaliberNation\Model\Service\Pricing\PriceBasis;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Applies the additive member price to catalog line items for ACTIVE members.
 *
 * Runs on sales_quote_collect_totals_before — i.e. BEFORE the cart price-rule /
 * discount collector — so the member price becomes the item's effective base
 * price and coupons/cart rules stack on top of it (P0 §1.5). Uses setCustomPrice,
 * mirroring the win-back observer.
 *
 * The membership product itself is skipped (it has its own price and is handled
 * by the win-back observer for lapsed members).
 */
class ApplyMemberPrice implements ObserverInterface
{
    /** Regular (pre-member) unit price, stashed for cart/checkout display. */
    public const KEY_REGULAR_PRICE = 'cn_regular_price';

    /** Per-unit member saving (regular - member price). */
    public const KEY_MEMBER_SAVINGS = 'cn_member_savings';

    public function __construct(
        private readonly Config $config,
        private readonly MemberAccess $memberAccess,
        private readonly MemberPriceResolver $resolver,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly PriceBasis $priceBasis
    ) {}

    /**
     * A quote item's own getProduct() is a lightweight object that does NOT carry
     * custom EAV attributes (caliber_member_discount_*) once the quote is reloaded
     * from the DB — only the id/sku/price basics needed for totals. Re-loading via
     * the repository (which caches per id+store) gives the resolver the real
     * product-level discount attributes, same as the PDP teaser sees.
     */
    private function loadFullProduct(\Magento\Quote\Model\Quote\Item $item): ?\Magento\Catalog\Api\Data\ProductInterface
    {
        $itemProduct = $item->getProduct();
        if (!$itemProduct || !$itemProduct->getId()) {
            return null;
        }
        try {
            return $this->productRepository->getById((int) $itemProduct->getId(), false, $item->getStoreId());
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }
        if (!$this->config->isPricingEnabled()) {
            return;
        }

    $quote = $observer->getEvent()->getData('quote');
        if (!$quote || !$quote->getCustomerId()) {
            return;
        }

        $customerId = (int) $quote->getCustomerId();
        $isActive   = $this->memberAccess->isActiveMember($customerId);

        $membershipSku = $this->config->getMembershipSku();

        foreach ($quote->getAllItems() as $item) {
            // Skip the membership product itself and child/duplicate rows.
            if ($item->getSku() === $membershipSku || $item->getParentItemId()) {
                continue;
            }
            $product = $this->loadFullProduct($item);
            if (!$product) {
                continue;
            }

            if ($isActive) {
                // final_price, not the `price` attribute: a product on special must
                // be discounted off its SALE price, or the member is charged more
                // than a guest. See PriceBasis.
                $regular = $this->priceBasis->get($product);
                $result  = $this->resolver->resolveForProduct($product, $regular);
                if (!$result->hasDiscount()) {
                    if ($item->getCustomPrice() !== null) {
                        $item->setCustomPrice(null);
                        $item->setOriginalCustomPrice(null);
                        $this->clearMemberPriceData($item);
                    }
                    continue;
                }
                $item->setCustomPrice($result->memberPrice);
                $item->setOriginalCustomPrice($result->memberPrice);
                $item->getProduct()->setIsSuperMode(true);

                // setCustomPrice() OVERWRITES the line price: afterwards price,
                // base_price, custom_price and row_total all read the member
                // price, and discount_amount stays 0.00 (a custom price is not a
                // discount in Magento's model). The regular price therefore
                // survives nowhere on the item, so the cart and checkout cannot
                // show "was $30, now $10" without us stashing it here.
                //
                // Set ONLY on lines CaliberNation actually discounted, so free
                // gifts and ordinary sale items are never mislabelled as member
                // savings.
                // Only advertise a saving when the line is ACTUALLY charged the
                // member price. A promotional free gift (Amasty) reaches this
                // point priced at 0.00 with no distinguishing flag of its own, so
                // trusting the resolver's arithmetic alone would label a free item
                // "member saves $2.09" — money the customer never saved.
                $charged = (float) $item->getPrice();
                if (abs($charged - $result->memberPrice) < 0.01 && $regular > $result->memberPrice) {
                    $item->setData(self::KEY_REGULAR_PRICE, $regular);
                    $item->setData(self::KEY_MEMBER_SAVINGS, $regular - $result->memberPrice);
                } else {
                    $this->clearMemberPriceData($item);
                }
            } elseif ($item->getCustomPrice() !== null) {
                // Member is no longer active but a member price is still frozen on
                // this line (e.g. they lapsed / entered renewal_pending after adding
                // it while active). Clear it so the regular catalog price applies
                // again. Guarded by the resolver so we only undo lines CaliberNation
                // itself would have priced — never a custom price set elsewhere.
                // Same basis as the apply path above, so this correctly recognises
                // the lines CaliberNation itself priced.
                $regular = $this->priceBasis->get($product);
                if ($this->resolver->resolveForProduct($product, $regular)->hasDiscount()) {
                    $item->setCustomPrice(null);
                    $item->setOriginalCustomPrice(null);
                    $this->clearMemberPriceData($item);
                }
            }
        }
    }

    /** Drop the display markers when a line is no longer member-priced. */
    private function clearMemberPriceData($item): void
    {
        $item->setData(self::KEY_REGULAR_PRICE, null);
        $item->setData(self::KEY_MEMBER_SAVINGS, null);
    }
}
