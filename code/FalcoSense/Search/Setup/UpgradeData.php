<?php
declare(strict_types=1);

namespace FalcoSense\Search\Setup;

use Magento\Framework\Setup\UpgradeDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;

class UpgradeData implements UpgradeDataInterface
{
    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        if (version_compare($context->getVersion(), '1.0.1', '<')) {
            $connection = $setup->getConnection();

            $connection->insertOnDuplicate(
                $setup->getTable('falcosense_style_component'),
                ['code' => 'add_to_cart_button', 'label' => 'Add to Cart Button'],
                ['label']
            );
            $componentId = (int)$connection->fetchOne(
                $connection->select()
                    ->from($setup->getTable('falcosense_style_component'), 'component_id')
                    ->where('code = ?', 'add_to_cart_button')
            );

            $connection->insertOnDuplicate(
                $setup->getTable('falcosense_style_attribute'),
                [
                    'component_id' => $componentId,
                    'code' => 'border_radius',
                    'input_type' => 'slider',
                    'default_value' => '9999',
                    'sort_order' => 10,
                ],
                ['input_type', 'default_value', 'sort_order']
            );
        }

        if (version_compare($context->getVersion(), '1.0.2', '<')) {
            $connection = $setup->getConnection();

            $componentId = (int)$connection->fetchOne(
                $connection->select()
                    ->from($setup->getTable('falcosense_style_component'), 'component_id')
                    ->where('code = ?', 'add_to_cart_button')
            );

            $connection->insertOnDuplicate(
                $setup->getTable('falcosense_style_attribute'),
                [
                    'component_id' => $componentId,
                    'code' => 'icon',
                    'input_type' => 'image',
                    'default_value' => '',
                    'sort_order' => 20,
                ],
                ['input_type', 'default_value', 'sort_order']
            );

            $connection->insertOnDuplicate(
                $setup->getTable('falcosense_style_attribute'),
                [
                    'component_id' => $componentId,
                    'code' => 'button_style',
                    'input_type' => 'select',
                    'default_value' => 'outline',
                    'sort_order' => 30,
                ],
                ['input_type', 'default_value', 'sort_order']
            );

            $connection->insertOnDuplicate(
                $setup->getTable('falcosense_style_attribute'),
                [
                    'component_id' => $componentId,
                    'code' => 'label_text',
                    'input_type' => 'text',
                    'default_value' => 'Add',
                    'sort_order' => 40,
                ],
                ['input_type', 'default_value', 'sort_order']
            );
        }

        if (version_compare($context->getVersion(), '1.0.3', '<')) {
            $connection = $setup->getConnection();

            // label_text was briefly seeded with default_value='Add', which would have
            // silently overwritten every Options button's text site-wide since it's shared
            // by both buttons. Empty means "keep each button's own original text" instead.
            $connection->update(
                $setup->getTable('falcosense_style_attribute'),
                ['default_value' => ''],
                ['code = ?' => 'label_text']
            );
        }

        if (version_compare($context->getVersion(), '1.0.4', '<')) {
            $connection = $setup->getConnection();

            $componentId = (int)$connection->fetchOne(
                $connection->select()
                    ->from($setup->getTable('falcosense_style_component'), 'component_id')
                    ->where('code = ?', 'add_to_cart_button')
            );

            // label_text now covers only the Add button; options_label_text is a
            // separate field so the two buttons can carry different text.
            $connection->insertOnDuplicate(
                $setup->getTable('falcosense_style_attribute'),
                [
                    'component_id' => $componentId,
                    'code' => 'options_label_text',
                    'input_type' => 'text',
                    'default_value' => '',
                    'sort_order' => 50,
                ],
                ['input_type', 'default_value', 'sort_order']
            );
        }

        if (version_compare($context->getVersion(), '1.0.5', '<')) {
            $connection = $setup->getConnection();

            $connection->insertOnDuplicate(
                $setup->getTable('falcosense_style_component'),
                ['code' => 'product_card', 'label' => 'Product Card'],
                ['label']
            );
            $componentId = (int)$connection->fetchOne(
                $connection->select()
                    ->from($setup->getTable('falcosense_style_component'), 'component_id')
                    ->where('code = ?', 'product_card')
            );

            // Defaults below all reproduce the site's current, pre-existing appearance
            // exactly (no border/shadow, no rounding, 2px gap, 3 desktop columns) so
            // installing this component changes nothing until an admin edits a value.
            $attributes = [
                ['code' => 'corner_radius', 'input_type' => 'slider', 'default_value' => '0', 'sort_order' => 10],
                ['code' => 'border_shadow_style', 'input_type' => 'select', 'default_value' => 'none', 'sort_order' => 20],
                ['code' => 'card_spacing', 'input_type' => 'slider', 'default_value' => '2', 'sort_order' => 30],
                ['code' => 'columns_per_row', 'input_type' => 'select', 'default_value' => '3', 'sort_order' => 40],
            ];
            foreach ($attributes as $attribute) {
                $connection->insertOnDuplicate(
                    $setup->getTable('falcosense_style_attribute'),
                    array_merge(['component_id' => $componentId], $attribute),
                    ['input_type', 'default_value', 'sort_order']
                );
            }
        }

        $setup->endSetup();
    }
}
