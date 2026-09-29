<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin\Catalog\Model\Product;

use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;

/**
 * Site-wide: a simple/virtual/bundle product with fewer than MIN_QTY units
 * in stock is treated as not salable, even though Magento's own boolean
 * is_in_stock flag may still read true (that flag typically only flips at
 * qty <= 0).
 *
 * Configurable PARENTS are deliberately skipped here: their own qty is
 * meaningless (Magento doesn't track stock on the configurable itself).
 * Magento\ConfigurableProduct\Model\Product\Type\Configurable::isSalable()
 * already aggregates over children -- a configurable parent stays salable as
 * long as AT LEAST ONE child is salable -- and that aggregation calls
 * $child->isSalable() for each child individually, which re-enters THIS
 * plugin per child. The net effect: one low-qty variant loses its own
 * salability (so Configurable::getAllowProducts() drops it and its swatch
 * renders disabled), while the parent product only flips to "out of stock"
 * once every variant has failed the same way -- no separate parent-level
 * handling needed.
 */
class MinQtySalablePlugin
{
    private const MIN_QTY = 6;

    public function __construct(
        private readonly StockRegistryInterface $stockRegistry
    ) {
    }

    public function afterIsSalable(Product $subject, $result)
    {
        if (!$result || $subject->getTypeId() === Configurable::TYPE_CODE) {
            return $result;
        }

        $stockItem = $this->stockRegistry->getStockItem((int) $subject->getId());

        if ((float) $stockItem->getQty() < self::MIN_QTY) {
            return false;
        }

        return $result;
    }
}
