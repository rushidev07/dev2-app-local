<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Removes the per-product early-access date override attributes that are no
 * longer needed now that global config dates are used exclusively:
 *
 *  - caliber_ea_member_start_at
 *  - caliber_ea_public_start_at
 */
class RemoveEarlyAccessDateOverrideAttributes implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        foreach (['caliber_ea_member_start_at', 'caliber_ea_public_start_at'] as $code) {
            if ($eavSetup->getAttributeId(Product::ENTITY, $code)) {
                $eavSetup->removeAttribute(Product::ENTITY, $code);
            }
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddEarlyAccessProductAttributes::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
