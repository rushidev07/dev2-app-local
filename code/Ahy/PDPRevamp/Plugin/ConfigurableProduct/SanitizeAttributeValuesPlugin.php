<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin\ConfigurableProduct;

use Magento\ConfigurableProduct\Helper\Data as ConfigurableHelper;

/**
 * Guards against a core Magento fatal - TypeError: Illegal offset type in
 * vendor/magento/module-configurable-product/Helper/Data.php - that takes
 * down the entire PDP (500, response body swapped for plain text) whenever
 * one of a configurable product's "used in configuration" attributes holds
 * a non-scalar value on one of its child (simple) products. Core's
 * getOptions() does `$options[$productAttributeId][$attributeValue][] = ...`
 * to build the swatch/options JSON, and in PHP 8 an array/object used as
 * that second offset is an illegal array key, not a warning.
 *
 * Seen live on "2P DynaLite Tent" (product 458288): one of its configuration
 * attributes returns an array from $product->getData() for at least one
 * child SKU - a bad/legacy EAV value, not something the storefront can fix.
 * Rather than chasing down and repairing every affected SKU's row, this
 * collapses any such value to its first scalar element (or null) before
 * core ever touches it, so the page renders - with that one option simply
 * unavailable - instead of 500ing the whole PDP.
 */
class SanitizeAttributeValuesPlugin
{
    public function beforeGetOptions(ConfigurableHelper $subject, $currentProduct, array $allowedProducts): void
    {
        $attributeCodes = [];
        foreach ($subject->getAllowAttributes($currentProduct) as $attribute) {
            $attributeCodes[] = $attribute->getProductAttribute()->getAttributeCode();
        }

        foreach ($allowedProducts as $product) {
            foreach ($attributeCodes as $code) {
                $value = $product->getData($code);
                if (is_array($value) || is_object($value)) {
                    $scalar = is_array($value) ? reset($value) : null;
                    $product->setData($code, is_scalar($scalar) ? $scalar : null);
                }
            }
        }
    }
}
