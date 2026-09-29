<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * CreateExitPopupEnabledAttribute and CreateFbtForceManualAttribute originally
 * placed both toggles in "Key Features". Admin wants them moved next to the
 * AI Descriptors field (pdp_descriptors, "General" group, sort_order 210)
 * instead, so they sit together with the other PDP-content-editing fields
 * rather than the unrelated key-features editor.
 *
 * "group" and "sort_order" are not columns on eav_attribute/catalog_eav_
 * attribute - they live in eav_entity_attribute, one row per attribute SET
 * (this install has 45+ sets), so EavSetup::updateAttribute() cannot move
 * them (it silently no-ops on unknown fields). addAttributeToSet() is the
 * real per-set group reassignment used internally by addAttribute() itself;
 * looping every attribute set matches what the original creation patches did
 * when they first placed these under "Key Features". New installs get the
 * right group/sort_order directly from the two updated creation patches.
 */
class MoveExitPopupAndFbtForceManualToGeneralGroup implements DataPatchInterface
{
    private const GENERAL_GROUP = 'General';

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

        $this->moveAttribute($eavSetup, CreateExitPopupEnabledAttribute::ATTRIBUTE_CODE, 220);
        $this->moveAttribute($eavSetup, CreateFbtForceManualAttribute::ATTRIBUTE_CODE, 230);

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    private function moveAttribute(\Magento\Eav\Setup\EavSetup $eavSetup, string $code, int $sortOrder): void
    {
        $connection = $this->moduleDataSetup->getConnection();
        $setIds = $connection->fetchCol(
            $connection->select()->from(
                $this->moduleDataSetup->getTable('eav_attribute_set'),
                'attribute_set_id'
            )->where(
                'entity_type_id = ?',
                $eavSetup->getEntityTypeId(Product::ENTITY)
            )
        );

        foreach ($setIds as $setId) {
            $eavSetup->addAttributeGroup(Product::ENTITY, $setId, self::GENERAL_GROUP);
            $eavSetup->addAttributeToSet(Product::ENTITY, $setId, self::GENERAL_GROUP, $code, $sortOrder);
        }

        // Shared sort_order column on eav_entity_attribute isn't touched by
        // addAttributeToSet() once the row already exists (only on insert) -
        // set it explicitly via updateAttribute()'s $sortOrder param.
        $eavSetup->updateAttribute(Product::ENTITY, $code, [], null, $sortOrder);
    }

    public static function getDependencies(): array
    {
        return [
            CreateExitPopupEnabledAttribute::class,
            CreateFbtForceManualAttribute::class,
            CreatePdpDescriptorsAttribute::class,
        ];
    }

    public function getAliases(): array
    {
        return [];
    }
}
