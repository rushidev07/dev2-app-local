<?php
declare(strict_types=1);

namespace FalcoSense\Search\Setup;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class UpgradeSchema implements UpgradeSchemaInterface
{
    public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        if (version_compare($context->getVersion(), '1.0.1', '<')) {
            $this->createStyleComponentTable($setup);
            $this->createStyleAttributeTable($setup);
            $this->createStyleValueTable($setup);
        }

        $setup->endSetup();
    }

    private function createStyleComponentTable(SchemaSetupInterface $setup): void
    {
        $tableName = $setup->getTable('falcosense_style_component');

        if ($setup->getConnection()->isTableExists($tableName)) {
            return;
        }

        $table = $setup->getConnection()->newTable($tableName)
            ->addColumn(
                'component_id',
                Table::TYPE_SMALLINT,
                null,
                ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true],
                'Component ID'
            )
            ->addColumn(
                'code',
                Table::TYPE_TEXT,
                64,
                ['nullable' => false],
                'Component Code'
            )
            ->addColumn(
                'label',
                Table::TYPE_TEXT,
                255,
                ['nullable' => false],
                'Component Label'
            )
            ->addIndex(
                $setup->getIdxName('falcosense_style_component', ['code'], \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE),
                ['code'],
                ['type' => \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE]
            )
            ->setComment('FalcoSense Style Component');

        $setup->getConnection()->createTable($table);
    }

    private function createStyleAttributeTable(SchemaSetupInterface $setup): void
    {
        $tableName = $setup->getTable('falcosense_style_attribute');

        if ($setup->getConnection()->isTableExists($tableName)) {
            return;
        }

        $table = $setup->getConnection()->newTable($tableName)
            ->addColumn(
                'attribute_id',
                Table::TYPE_SMALLINT,
                null,
                ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true],
                'Attribute ID'
            )
            ->addColumn(
                'component_id',
                Table::TYPE_SMALLINT,
                null,
                ['unsigned' => true, 'nullable' => false],
                'Component ID'
            )
            ->addColumn(
                'code',
                Table::TYPE_TEXT,
                64,
                ['nullable' => false],
                'Attribute Code'
            )
            ->addColumn(
                'input_type',
                Table::TYPE_TEXT,
                32,
                ['nullable' => false],
                'Input Type: color, slider, select, text, boolean, image, json'
            )
            ->addColumn(
                'default_value',
                Table::TYPE_TEXT,
                '2M',
                ['nullable' => true],
                'Default Value'
            )
            ->addColumn(
                'sort_order',
                Table::TYPE_SMALLINT,
                null,
                ['unsigned' => true, 'nullable' => false, 'default' => 0],
                'Sort Order'
            )
            ->addIndex(
                $setup->getIdxName('falcosense_style_attribute', ['component_id', 'code'], \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE),
                ['component_id', 'code'],
                ['type' => \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE]
            )
            ->addForeignKey(
                $setup->getFkName('falcosense_style_attribute', 'component_id', 'falcosense_style_component', 'component_id'),
                'component_id',
                $setup->getTable('falcosense_style_component'),
                'component_id',
                \Magento\Framework\DB\Ddl\Table::ACTION_CASCADE
            )
            ->setComment('FalcoSense Style Attribute');

        $setup->getConnection()->createTable($table);
    }

    private function createStyleValueTable(SchemaSetupInterface $setup): void
    {
        $tableName = $setup->getTable('falcosense_style_value');

        if ($setup->getConnection()->isTableExists($tableName)) {
            return;
        }

        $table = $setup->getConnection()->newTable($tableName)
            ->addColumn(
                'value_id',
                Table::TYPE_INTEGER,
                null,
                ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true],
                'Value ID'
            )
            ->addColumn(
                'attribute_id',
                Table::TYPE_SMALLINT,
                null,
                ['unsigned' => true, 'nullable' => false],
                'Attribute ID'
            )
            ->addColumn(
                'scope',
                Table::TYPE_TEXT,
                8,
                ['nullable' => false, 'default' => 'default'],
                'Config Scope: default, websites, stores'
            )
            ->addColumn(
                'scope_id',
                Table::TYPE_SMALLINT,
                null,
                ['unsigned' => true, 'nullable' => false, 'default' => 0],
                'Scope ID'
            )
            ->addColumn(
                'value',
                Table::TYPE_TEXT,
                '2M',
                ['nullable' => true],
                'Stored Value'
            )
            ->addColumn(
                'updated_at',
                Table::TYPE_TIMESTAMP,
                null,
                ['nullable' => false, 'default' => Table::TIMESTAMP_INIT_UPDATE],
                'Updated At'
            )
            ->addIndex(
                $setup->getIdxName('falcosense_style_value', ['attribute_id', 'scope', 'scope_id'], \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE),
                ['attribute_id', 'scope', 'scope_id'],
                ['type' => \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE]
            )
            ->addForeignKey(
                $setup->getFkName('falcosense_style_value', 'attribute_id', 'falcosense_style_attribute', 'attribute_id'),
                'attribute_id',
                $setup->getTable('falcosense_style_attribute'),
                'attribute_id',
                \Magento\Framework\DB\Ddl\Table::ACTION_CASCADE
            )
            ->setComment('FalcoSense Style Value');

        $setup->getConnection()->createTable($table);
    }
}
