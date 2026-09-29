<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\ViewModel;

use Ahy\PDPRevamp\Model\ResourceModel\NativeColorSwatch;
use Ahy\PDPRevamp\Model\ResourceModel\VariantColor;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * The colour option-id => hex maps a configurable's swatches need, for one
 * specific product.
 *
 * product/color-swatch-resolver.phtml publishes exactly these maps into
 * window.AHY_ADMIN_COLOR_HEX / window.AHY_NATIVE_SWATCH_HEX, but it builds
 * them from the CURRENT product only (Hyva's CurrentProduct view model). That
 * is all the main PDP ever needs - but the FBT bundle's modal renders the
 * swatches of *other* products into the same page, and their colour option ids
 * are absent from those maps, so window.ahyGetSwatchStyle() returns null for
 * every one of them and swatch-renderer.phtml drops them all into its
 * plain-text pass instead of showing real swatches.
 *
 * Rendering this per-product map from swatch-renderer.phtml itself keeps each
 * copy of the swatches self-sufficient wherever it is rendered - main PDP,
 * FBT modal, or anything else that reuses that template - rather than making
 * the head-level resolver block guess in advance which other products a page
 * might end up showing.
 *
 * Option ids are attribute-option ids, so an id means the same colour for
 * every product that uses it: these maps are merged into the globals, never
 * assigned over them.
 */
class ColorSwatchMap implements ArgumentInterface
{
    private VariantColor $variantColor;
    private NativeColorSwatch $nativeColorSwatch;

    public function __construct(
        VariantColor $variantColor,
        NativeColorSwatch $nativeColorSwatch
    ) {
        $this->variantColor = $variantColor;
        $this->nativeColorSwatch = $nativeColorSwatch;
    }

    /**
     * @return array{admin: array<int, string>, native: array<int, string>}
     */
    public function getMapsForProduct(?Product $product): array
    {
        $empty = ['admin' => [], 'native' => []];

        if (!$product || !$product->getId() || $product->getTypeId() !== Configurable::TYPE_CODE) {
            return $empty;
        }

        $colorValueByChildId = [];
        foreach ($product->getTypeInstance()->getUsedProducts($product) as $child) {
            $colorValue = $child->getData('color');
            if ($colorValue === null || $colorValue === '') {
                continue;
            }
            $colorValueByChildId[(int) $child->getId()] = (int) $colorValue;
        }

        if (!$colorValueByChildId) {
            return $empty;
        }

        // Per-variant hex picked in the admin Configurations grid wins over the
        // attribute option's own native swatch - same precedence as
        // window.ahyGetSwatchStyle() applies when reading these two maps.
        $adminHexByOptionValue = [];
        foreach ($this->variantColor->getHexByProductIds(array_keys($colorValueByChildId)) as $childId => $hex) {
            $colorValue = $colorValueByChildId[(int) $childId] ?? null;
            if ($colorValue !== null && !isset($adminHexByOptionValue[$colorValue])) {
                $adminHexByOptionValue[$colorValue] = $hex;
            }
        }

        return [
            'admin' => $adminHexByOptionValue,
            'native' => $this->nativeColorSwatch->getHexByOptionIds(
                array_values(array_unique($colorValueByChildId))
            ),
        ];
    }
}
