<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin\Bss\ProductStockAlert\Block\Product\View;

use Bss\ProductStockAlert\Block\Product\View\Stock;

/**
 * Stock::checkStatusByStockId() (used by Stock::setTemplate() to decide
 * whether to render the "Email me when available" form) only checks
 * Magento's raw is_in_stock boolean / MSI salable qty -- it never calls
 * Product::isSalable(), so it never picks up the MIN_QTY floor from
 * Ahy\PDPRevamp\Plugin\Catalog\Model\Product\MinQtySalablePlugin. A simple
 * product with qty=3 reads as "in stock" here (raw boolean only flips at
 * qty <= 0) even though the PDP's own stock-status widget already shows it
 * as Out of Stock via isSalable(). This plugin makes the two agree: if the
 * product itself is not salable, treat it as NOT sufficiently in stock so
 * the "notify me" form shows alongside the Out of Stock label.
 */
class ShowStockAlertForLowQtyPlugin
{
    public function afterCheckStatusByStockId(Stock $subject, $result, $productId, $productSku)
    {
        if (!$result) {
            return $result;
        }

        $product = $subject->getProduct();
        if ($product && (int) $product->getId() === (int) $productId && !$product->isSalable()) {
            return false;
        }

        return $result;
    }
}
