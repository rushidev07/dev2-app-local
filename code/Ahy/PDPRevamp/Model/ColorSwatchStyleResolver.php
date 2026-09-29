<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Model;

use Ahy\PDPRevamp\Model\ResourceModel\NativeColorSwatch;
use Ahy\PDPRevamp\Model\ResourceModel\VariantColor;
use Magento\Catalog\Model\Product;
use Magento\Swatches\Helper\Media as SwatchMediaHelper;

/**
 * Server-side equivalent of window.ahyGetSwatchStyle() /
 * window.ahyBuildSwatchBackground() (see product/color-swatch-resolver.phtml)
 * for contexts that render outside the PDP - e.g. the mini cart - where the
 * page has no configurable-product option list to hang the JS resolver's
 * AHY_ADMIN_COLOR_HEX/AHY_NATIVE_SWATCH_HEX globals off of, and may be
 * showing items for several unrelated products at once. Resolves a single
 * product straight to a ready-to-use CSS "background" declaration, in the
 * same priority order as the PDP resolver:
 *  1. Admin-set exact hex for this simple-product variant (ahy_pdprevamp_variant_color).
 *  2. Magento's own native visual swatch for the product's `color` option (eav_attribute_option_swatch).
 *  3. The given label matched against the CSS3 color-name table.
 */
class ColorSwatchStyleResolver
{
    private VariantColor $variantColor;
    private NativeColorSwatch $nativeColorSwatch;
    private CssColorMap $cssColorMap;
    private SwatchMediaHelper $swatchMediaHelper;

    public function __construct(
        VariantColor $variantColor,
        NativeColorSwatch $nativeColorSwatch,
        CssColorMap $cssColorMap,
        SwatchMediaHelper $swatchMediaHelper
    ) {
        $this->variantColor = $variantColor;
        $this->nativeColorSwatch = $nativeColorSwatch;
        $this->cssColorMap = $cssColorMap;
        $this->swatchMediaHelper = $swatchMediaHelper;
    }

    /**
     * @param Product $product The simple product variant (e.g. a quote item's getProduct()).
     * @param string|null $label Fallback color name, e.g. the product's own `color` attribute text.
     * @return string|null A CSS "background..." declaration, or null if no swatch could be resolved.
     */
    public function resolveForProduct(Product $product, ?string $label = null): ?string
    {
        $adminHex = $this->variantColor->getHexByProductId((int) $product->getId());
        if ($adminHex) {
            return $this->buildBackground($adminHex);
        }

        $colorOptionId = (int) $product->getData('color');
        if ($colorOptionId) {
            $nativeHexByOptionId = $this->nativeColorSwatch->getHexByOptionIds([$colorOptionId]);
            if (!empty($nativeHexByOptionId[$colorOptionId])) {
                return $this->buildBackground($nativeHexByOptionId[$colorOptionId]);
            }
        }

        if ($label) {
            $cssHex = $this->cssColorMap->getHex(CssColorMap::normalize($label));
            if ($cssHex) {
                return 'background-color:' . $cssHex . ';';
            }
        }

        return null;
    }

    /**
     * Mirrors window.ahyBuildSwatchBackground() - a raw swatch value is
     * either a single hex/image-path, or two comma-separated halves of
     * either for a two-color option, rendered as a straight left/right
     * split rather than solid.
     */
    private function buildBackground(string $rawValue): string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $rawValue, 2))));

        if (count($parts) < 2) {
            return $this->isImagePath($parts[0])
                ? sprintf(
                    "background-image:url('%s');background-size:cover;background-position:center;",
                    $this->toImageUrl($parts[0])
                )
                : 'background-color:' . $parts[0] . ';';
        }

        if (!$this->isImagePath($parts[0]) && !$this->isImagePath($parts[1])) {
            return sprintf('background: linear-gradient(90deg, %s 50%%, %s 50%%);', $parts[0], $parts[1]);
        }

        $toLayer = function (string $part): string {
            return $this->isImagePath($part)
                ? sprintf("url('%s')", $this->toImageUrl($part))
                : sprintf('linear-gradient(%s,%s)', $part, $part);
        };

        return 'background-image:' . $toLayer($parts[0]) . ',' . $toLayer($parts[1]) . ';'
            . 'background-position:left center,right center;'
            . 'background-size:50% 100%,50% 100%;'
            . 'background-repeat:no-repeat,no-repeat;';
    }

    private function isImagePath(string $part): bool
    {
        return $part !== '' && $part[0] === '/';
    }

    private function toImageUrl(string $relativePath): string
    {
        return $this->swatchMediaHelper->getSwatchMediaUrl() . $relativePath;
    }
}
