<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Prevents saving a product when the Caliber Nation member discount type is
 * "percent" but the value exceeds 100.
 */
class ValidateProductMemberDiscount implements ObserverInterface
{
    public function execute(Observer $observer): void
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $product = $observer->getEvent()->getProduct();

        $type  = (string) $product->getData('caliber_member_discount_type');
        $value = (float) $product->getData('caliber_member_discount_value');

        if ($type === 'percent' && $value > 100) {
            throw new LocalizedException(
                __('Caliber Nation member discount cannot exceed 100%. Please enter a value between 0 and 100.')
            );
        }
    }
}
