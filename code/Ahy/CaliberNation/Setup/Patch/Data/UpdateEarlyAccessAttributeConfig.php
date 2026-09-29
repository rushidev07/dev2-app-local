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
 * - Renames caliber_early_access label to "Caliber Early Access Badge"
 * - Renames caliber_member_only label to "Restrict Visibility to Caliber Nation Members Only"
 * - Moves caliber_member_only into the "Caliber Nation Early Access" tab (same as caliber_early_access)
 */
class UpdateEarlyAccessAttributeConfig implements DataPatchInterface
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

        // Update caliber_early_access label to be more descriptive.
        $eavSetup->updateAttribute(
            Product::ENTITY,
            'caliber_early_access',
            'frontend_label',
            'Caliber Early Access Badge'
        );

        // Update caliber_member_only label and note to be more descriptive.
        $eavSetup->updateAttribute(
            Product::ENTITY,
            'caliber_member_only',
            'frontend_label',
            'Restrict Visibility to Caliber Nation Members Only'
        );
        $eavSetup->updateAttribute(
            Product::ENTITY,
            'caliber_member_only',
            'note',
            'When set to Yes, this product is hidden from guests and non-members everywhere on the site (category pages, search, PDP). Only active Caliber Nation members can see it.'
        );

        // Move caliber_member_only into the "Caliber Nation Early Access" group
        // across all product attribute sets.
        $entityTypeId   = $eavSetup->getEntityTypeId(Product::ENTITY);
        $attributeSetIds = $this->moduleDataSetup->getConnection()->fetchCol(
            $this->moduleDataSetup->getConnection()
                ->select()
                ->from($this->moduleDataSetup->getTable('eav_attribute_set'), 'attribute_set_id')
                ->where('entity_type_id = ?', $entityTypeId)
        );

        foreach ($attributeSetIds as $setId) {
            $groupId = $eavSetup->getAttributeGroupId(Product::ENTITY, $setId, self::GROUP);
            if ($groupId) {
                $eavSetup->addAttributeToGroup(
                    Product::ENTITY,
                    $setId,
                    $groupId,
                    'caliber_member_only',
                    20  // sort_order: just below caliber_early_access (10)
                );
            }
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [
            AddEarlyAccessProductAttributes::class,
            AddMemberOnlyProductAttribute::class,
        ];
    }

    public function getAliases(): array
    {
        return [];
    }
}
