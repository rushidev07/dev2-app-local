<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Ui\DataProvider\Product\Form\Modifier;

use Ahy\PDPRevamp\Model\Product\SpecificationsResolver;
use Ahy\PDPRevamp\Setup\Patch\Data\CreatePdpSpecificationsAttribute;
use Magento\Catalog\Model\ProductFactory;
use Magento\Ui\DataProvider\Modifier\ModifierInterface;

/**
 * Pre-fills the "pdp_specifications" textarea field (see
 * Setup/Patch/Data/CreatePdpSpecificationsAttribute and Block/Product/View/
 * Specifications) with the same fallback label/value table
 * SpecificationsResolver parses out of the plain description for the
 * storefront Specifications tab, when the attribute has no admin content of
 * its own yet - so opening a product that's only ever shown its table via
 * that fallback already shows it here too, instead of an empty textarea.
 *
 * The same table is also stripped out of the Description field shown
 * alongside it - mirroring Ui\DataProvider\Product\Form\Modifier\
 * KeyFeatures, which this runs after (see di.xml sortOrder), so it reads
 * whatever Key Features has already cleaned the bullet list out of, not the
 * raw description straight off the product.
 *
 * This only changes what the edit form displays; saving the product (a
 * normal admin Save, same as any other field) is what actually persists
 * both changes.
 */
class Specifications implements ModifierInterface
{
    private const ATTRIBUTE_CODE = CreatePdpSpecificationsAttribute::ATTRIBUTE_CODE;

    private ProductFactory $productFactory;
    private SpecificationsResolver $specificationsResolver;

    public function __construct(
        ProductFactory $productFactory,
        SpecificationsResolver $specificationsResolver
    ) {
        $this->productFactory = $productFactory;
        $this->specificationsResolver = $specificationsResolver;
    }

    public function modifyData(array $data): array
    {
        foreach ($data as $productId => $productData) {
            if (!isset($productData['product']) || !is_array($productData['product'])) {
                continue;
            }

            $existing = trim((string) ($productData['product'][self::ATTRIBUTE_CODE] ?? ''));
            if ($existing !== '') {
                continue;
            }

            $description = (string) ($productData['product']['description'] ?? '');
            if ($description === '') {
                continue;
            }

            // No real product needed here - only the fallback table parsing
            // runs, since we already know above the attribute has no admin
            // content to read off a loaded product.
            $productStub = $this->productFactory->create();
            $rows = $this->specificationsResolver->getSpecifications($productStub, $description);
            if (!$rows) {
                continue;
            }

            $lines = [];
            foreach ($rows as $row) {
                $lines[] = $row['label'] . ': ' . $row['value'];
            }
            $data[$productId]['product'][self::ATTRIBUTE_CODE] = implode("\n", $lines);

            $data[$productId]['product']['description'] = $this->specificationsResolver
                ->stripFallbackTableFromDescription($productStub, $description);
        }

        return $data;
    }

    public function modifyMeta(array $meta): array
    {
        return $meta;
    }
}
