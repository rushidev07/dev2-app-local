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
 * Adds the Early Access product attribute to the "Caliber Nation Early Access"
 * group on the product edit page:
 *
 *  - caliber_early_access  (Yes/No) — flag this product for early access
 */
class AddEarlyAccessProductAttributes implements DataPatchInterface
{
    private const GROUP = 'Caliber Nation Early Access';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        if (!$eavSetup->getAttributeId(Product::ENTITY, 'caliber_early_access')) {
            $eavSetup->addAttribute(Product::ENTITY, 'caliber_early_access', [
                'type'                    => 'int',
                'label'                   => 'Caliber Early Access',
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
                'note'                    => 'Enable to show the early-bird badge on this product for active members.',
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
