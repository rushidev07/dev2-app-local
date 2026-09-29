<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin\FalcoSense\Search\Controller\Configurable;

use Ahy\PDPRevamp\Model\CssColorMap;
use Ahy\PDPRevamp\Model\ResourceModel\NativeColorSwatch;
use Ahy\PDPRevamp\Model\ResourceModel\VariantColor;
use FalcoSense\Search\Controller\Configurable\Options;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Controller\Result\Json;
use Magento\Swatches\Helper\Media as SwatchMediaHelper;
use Psr\Log\LoggerInterface;

/**
 * Adds a per-variant `swatch` CSS-background field to FalcoSense_Search's
 * /smartsearch/configurable/options JSON response, using the same priority
 * chain as the real PDP's color swatches (see Ahy\PDPRevamp\Block\Product\
 * View\ColorSwatchResolver / product/color-swatch-resolver.phtml):
 *  1. Admin-set exact hex per simple-product variant (ahy_pdprevamp_variant_color).
 *  2. Magento's own native visual swatch per option (eav_attribute_option_swatch).
 *  3. The option's own label matched against the CSS3 color-name table.
 * Consumed by this module's own view/frontend/templates/modal/
 * config-modal.phtml, which renders a circular swatch when this resolves and
 * falls back to a plain text button otherwise (e.g. Size, or a color with no
 * configured swatch of any kind).
 *
 * FalcoSense_Search is outside this repo's Ahy/* ownership boundary, so this
 * is a plugin from PDPRevamp rather than an edit to that controller directly
 * - see etc/module.xml's sequence (FalcoSense_Search listed there so this
 * module's own layout override of its config-modal block reliably wins).
 *
 * Resolves the native swatch by the option's label text alone (already
 * present in the response, via Options::execute()'s own getAttributeText()
 * call) rather than reloading each variant product to read its raw option
 * id - a plugin only sees the method's return value, never its internals,
 * and reloading via ProductRepositoryInterface is not guaranteed to hydrate
 * every attribute depending on this store's repository configuration.
 */
class AddColorSwatchToOptionsResponse
{
    /**
     * Matches Ahy\PDPRevamp\Ui\DataProvider\Product\Form\Modifier\
     * VariantColorColumn::COLOR_ATTRIBUTE_CODE.
     */
    private const COLOR_ATTRIBUTE_CODE = 'color';

    public function __construct(
        private readonly VariantColor $variantColor,
        private readonly NativeColorSwatch $nativeColorSwatch,
        private readonly CssColorMap $cssColorMap,
        private readonly SwatchMediaHelper $swatchMediaHelper,
        private readonly EavConfig $eavConfig,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Wrapped in a single top-level try/catch: this must never turn a
     * previously-working quick-view request into a 500. Worst case on any
     * failure here (including the read/write of $result's data below, which
     * relies on a protected property via reflection - Json exposes setData()
     * but has no public getData()) is simply that no variant gets a `swatch`
     * field, and the modal falls back to its original plain text buttons.
     */
    public function afterExecute(Options $subject, Json $result): Json
    {
        // Temporary diagnostic: proves this plugin actually executes at all,
        // independent of everything below - set unconditionally, before any
        // logic that could fail. If this header is missing from the actual
        // HTTP response, the plugin itself is never running (a registration/
        // compile/caching problem, not a bug in the logic below); if it's
        // present but `swatch` still never appears on any variant, the
        // problem is inside the try block. Remove once the real cause is
        // confirmed either way.
        try {
            $result->setHeader('X-PDPRevamp-Plugin', 'ran', true);
        } catch (\Throwable $exception) {
            // Nothing to do - this is only a diagnostic aid.
        }

        try {
            $data = $this->readJsonData($result);

            if (!is_array($data)
                || empty($data['success'])
                || empty($data['variants'])
                || empty($data['configurable_attributes'])
            ) {
                return $result;
            }

            $colorLabel = null;
            foreach ($data['configurable_attributes'] as $attribute) {
                if (($attribute['code'] ?? null) === self::COLOR_ATTRIBUTE_CODE) {
                    $colorLabel = $attribute['label'] ?? null;
                    break;
                }
            }

            if ($colorLabel === null) {
                return $result;
            }

            foreach ($data['variants'] as &$variant) {
                $colorValue = $variant['attributes'][$colorLabel] ?? null;
                if ($colorValue === null || empty($variant['variant_id'])) {
                    continue;
                }

                $variant['swatch'] = $this->resolveColorSwatch((int) $variant['variant_id'], (string) $colorValue);
            }
            unset($variant);

            $result->setData($data);
        } catch (\Throwable $exception) {
            $this->logger->error(
                '[PDPRevamp] could not add color swatch to configurable options response: '
                . $exception->getMessage()
            );
        }

        return $result;
    }

    /**
     * Magento\Framework\Controller\Result\Json has setData() but no public
     * getData() - the underlying array is only readable via a protected
     * property, whose exact name varies across Magento versions/forks (a
     * first attempt hardcoding the name "data" turned out wrong on this
     * install and silently no-opped every time, caught by the try/catch
     * above with nothing to show for it). Instead of guessing a name, this
     * scans every property (including ones declared on parent classes -
     * ReflectionObject::getProperties() already walks the hierarchy) for
     * the one actually holding our response shape, identified by carrying
     * both a 'success' and a 'variants' key - the two this plugin always
     * needs anyway, so no false positive risk from some unrelated property.
     */
    private function readJsonData(Json $result): mixed
    {
        $reflection = new \ReflectionObject($result);

        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($result);

            if (is_array($value) && array_key_exists('success', $value) && array_key_exists('variants', $value)) {
                return $value;
            }
        }

        return null;
    }

    private function resolveColorSwatch(int $variantId, string $label): ?string
    {
        $adminHex = $this->variantColor->getHexByProductId($variantId);
        if ($adminHex) {
            return $this->buildSwatchBackground($adminHex);
        }

        $colorAttributeId = $this->getColorAttributeId();
        if ($colorAttributeId !== null) {
            // Resolved from the label text itself (already provided by the
            // caller, sourced from getAttributeText() in FalcoSense's own
            // Options::execute()) rather than by reloading the product to
            // read its raw option id - avoids depending on whether this
            // store's product repository configuration hydrates the color
            // attribute at all, which a plain ProductRepositoryInterface::
            // getById() reload is not guaranteed to do.
            $colorOptionId = $this->nativeColorSwatch->getOptionIdByLabel($colorAttributeId, $label);
            if ($colorOptionId !== null) {
                $nativeHexByOptionId = $this->nativeColorSwatch->getHexByOptionIds([$colorOptionId]);
                if (!empty($nativeHexByOptionId[$colorOptionId])) {
                    return $this->buildSwatchBackground($nativeHexByOptionId[$colorOptionId]);
                }
            }
        }

        $cssHex = $this->cssColorMap->getHex(CssColorMap::normalize($label));

        return $cssHex ? 'background-color:' . $cssHex . ';' : null;
    }

    private function getColorAttributeId(): ?int
    {
        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, self::COLOR_ATTRIBUTE_CODE);

        return $attribute && $attribute->getAttributeId() ? (int) $attribute->getAttributeId() : null;
    }

    /**
     * Mirrors window.ahyBuildSwatchBackground() (product/color-swatch-resolver.phtml)
     * - a raw swatch value is either a single hex/image-path, or two
     * comma-separated halves of either for a two-color option, rendered as a
     * straight left/right split rather than solid.
     */
    private function buildSwatchBackground(string $rawValue): string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $rawValue, 2))));

        if (count($parts) < 2) {
            return $this->isSwatchImagePath($parts[0])
                ? sprintf(
                    "background-image:url('%s');background-size:cover;background-position:center;",
                    $this->swatchMediaHelper->getSwatchMediaUrl() . $parts[0]
                )
                : 'background-color:' . $parts[0] . ';';
        }

        if (!$this->isSwatchImagePath($parts[0]) && !$this->isSwatchImagePath($parts[1])) {
            return sprintf('background: linear-gradient(90deg, %s 50%%, %s 50%%);', $parts[0], $parts[1]);
        }

        $toLayer = function (string $part): string {
            return $this->isSwatchImagePath($part)
                ? sprintf("url('%s')", $this->swatchMediaHelper->getSwatchMediaUrl() . $part)
                : sprintf('linear-gradient(%s,%s)', $part, $part);
        };

        return 'background-image:' . $toLayer($parts[0]) . ',' . $toLayer($parts[1]) . ';'
            . 'background-position:left center,right center;'
            . 'background-size:50% 100%,50% 100%;'
            . 'background-repeat:no-repeat,no-repeat;';
    }

    private function isSwatchImagePath(string $part): bool
    {
        return $part !== '' && $part[0] === '/';
    }
}
