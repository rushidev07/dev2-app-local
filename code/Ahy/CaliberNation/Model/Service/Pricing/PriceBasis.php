<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Service\Pricing;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;

/**
 * The price every member discount is calculated FROM.
 *
 * WHY THIS EXISTS
 * Member pricing used to start from $product->getPrice() - the `price` attribute,
 * i.e. the pre-sale figure the PDP labels MSRP. That ignores special_price and
 * catalog price rules, so on a discounted product the "member price" was computed
 * off a number nobody actually pays. On a $2,695 item marked down to $1,994.52 a
 * 10% member discount produced $2,425.50: a MEMBER paying $431 MORE than a guest,
 * advertised on the card as a saving, and charged that amount at checkout.
 *
 * final_price is Magento's "what this costs before cart rules" and already folds
 * in special_price, catalog rules and tier prices. Using it needs no
 * "is there a sale?" branch of our own:
 *   - no sale -> final_price == price, so the discount comes off MSRP (unchanged)
 *   - on sale -> final_price == the sale price, so the discount stacks on it
 *
 * Centralised deliberately. The old getPrice() call was duplicated across the
 * resolver, the cart observer, the quote re-pricer and the PDP view model, and
 * fixing only some of them would leave the DISPLAYED price and the CHARGED price
 * disagreeing. One definition, one place to change.
 */
class PriceBasis
{
    /**
     * Discount basis for a product, or 0.0 when no usable price can be read
     * (the resolver treats 0.0 as "no member pricing").
     */
    public function get(ProductInterface $product): float
    {
        $final = $this->finalPrice($product);
        if ($final > 0) {
            return $final;
        }

        // Configurables carry price 0 (the price lives on the children), so fall
        // back to the cheapest child's OWN basis - recursing keeps a child that is
        // itself on special from being priced off its MSRP.
        if ($product->getTypeId() === Configurable::TYPE_CODE) {
            return $this->lowestChildBasis($product);
        }

        return 0.0;
    }

    /**
     * Magento's final_price, falling back to the raw price attribute when the
     * price info is unavailable - a lightweight product object (e.g. one rebuilt
     * from a quote item) may have no price info attached.
     */
    private function finalPrice(ProductInterface $product): float
    {
        try {
            return (float) $product->getPriceInfo()
                ->getPrice('final_price')
                ->getAmount()
                ->getValue();
        } catch (\Throwable) {
            return (float) $product->getPrice();
        }
    }

    private function lowestChildBasis(ProductInterface $product): float
    {
        try {
            $prices = [];
            foreach ($product->getTypeInstance()->getUsedProducts($product) as $child) {
                $childPrice = $this->finalPrice($child);
                if ($childPrice > 0) {
                    $prices[] = $childPrice;
                }
            }
        } catch (\Throwable) {
            return 0.0;
        }

        return $prices ? min($prices) : 0.0;
    }
}
