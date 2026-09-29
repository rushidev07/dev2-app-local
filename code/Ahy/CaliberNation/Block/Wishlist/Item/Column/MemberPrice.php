<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Block\Wishlist\Item\Column;

use Magento\Catalog\Model\Product;
use Magento\Wishlist\Block\Customer\Wishlist\Item\Column;

/**
 * Renders the same Calibernation member-price box shown on the PDP
 * (Ahy_CaliberNation::product/member-price.phtml expects $block->getProduct()),
 * wired here as a wishlist grid column so the wishlist page can reuse that
 * template unchanged. Added as a sibling to the core wishlist item columns
 * (image/name/review/price/inner) in wishlist_index_index.xml, so it receives
 * the same setItem() call from Items::getColumns() that they do.
 */
class MemberPrice extends Column
{
    public function getProduct(): ?Product
    {
        $item = $this->getItem();

        return $item ? $item->getProduct() : null;
    }
}
