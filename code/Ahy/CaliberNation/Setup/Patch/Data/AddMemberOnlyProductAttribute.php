<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Adds the "Caliber Nation Members Only" product attribute.
 *
 * When enabled on a product (caliber_member_only = Yes), that product is
 * hidden from guests and non-members everywhere on the frontend (PLP, search,
 * widgets, PDP). Active Caliber Nation members see it normally.
 *
 * Default is No — no existing products are affected until explicitly toggled.
 */
class AddMemberOnlyProductAttribute implements DataPatchInterface
{
    private const GROUP = 'Caliber Nation';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        if (!$eavSetup->getAttributeId(Product::ENTITY, 'caliber_member_only')) {
            $eavSetup->addAttribute(Product::ENTITY, 'caliber_member_only', [
                'type'                    => 'int',
                'label'                   => 'Caliber Nation Members Only',
                'input'                   => 'boolean',
                'source'                  => Boolean::class,
                'required'                => false,
                'user_defined'            => true,
                'default'                 => '0',
                'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
                'group'                   => self::GROUP,
                'sort_order'              => 10,
                'visible'                 => true,
                'searchable'              => false,
                'filterable'              => false,
                'comparable'              => false,
                'visible_on_front'        => false,
                'used_in_product_listing' => true,
                'note'                    => 'When set to Yes, this product is visible only to active Caliber Nation members.',
            ]);
        }

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
