<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Adds "Bundle Discount Percent (This Bundle Only)" - a per-product override of
 * the store-wide FBT discount display.
 *
 * pdprevamp_fbt/general/discount_percent (see FrequentlyBoughtTogether block)
 * applies the same percent to every product's bundle. This attribute lets one
 * specific product's bundle - the one built from its own "Frequently Bought
 * Together (Manual)" picks, see Ui\DataProvider\Product\Form\Modifier\
 * ManualCarousels::getManualFbtFieldset() - advertise a different percent (or
 * none) without changing the site-wide value.
 *
 * Left empty (the default), the block falls back to the store-wide setting -
 * this is an override, not a replacement, so most products need never touch
 * it. Stored as decimal rather than int so a merchant can enter "12.5" without
 * it being silently truncated to "12".
 */
class CreateFbtBundleDiscountAttribute implements DataPatchInterface
{
    public const ATTRIBUTE_CODE = 'pdp_fbt_bundle_discount_percent';

    private ModuleDataSetupInterface $moduleDataSetup;
    private EavSetupFactory $eavSetupFactory;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        EavSetupFactory $eavSetupFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->eavSetupFactory = $eavSetupFactory;
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        /** @var \Magento\Eav\Setup\EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $eavSetup->addAttribute(
            Product::ENTITY,
            self::ATTRIBUTE_CODE,
            [
                'type' => 'decimal',
                'label' => 'Bundle Discount Percent (This Bundle Only)',
                'input' => 'text',
                'required' => false,
                'default' => null,
                'sort_order' => 26,
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                'group' => 'Key Features',
                'visible' => true,
                'visible_on_front' => false,
                'user_defined' => true,
                'is_used_in_grid' => false,
                'is_visible_in_grid' => false,
                'is_filterable_in_grid' => false,
                'note' => 'Overrides the store-wide FBT bundle discount percent for THIS product\'s bundle '
                    . 'only. Leave empty to use the store-wide value instead.',
            ]
        );

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
