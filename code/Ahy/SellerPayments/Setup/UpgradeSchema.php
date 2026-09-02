<?php

namespace Ahy\SellerPayments\Setup;

use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\DB\Ddl\Table;

class UpgradeSchema implements UpgradeSchemaInterface
{
    public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        // Only run this upgrade if upgrading to version 1.0.1 or above
        if (version_compare($context->getVersion(), '1.0.1', '<')) {
            if (!$setup->tableExists('ahy_seller_payment_info')) {
                $table = $setup->getConnection()->newTable(
                    $setup->getTable('ahy_seller_payment_info')
                )->addColumn(
                    'id',
                    Table::TYPE_INTEGER,
                    null,
                    ['identity' => true, 'nullable' => false, 'primary' => true, 'unsigned' => true],
                    'ID'
                )->addColumn(
                    'seller_id',
                    Table::TYPE_INTEGER,
                    null,
                    ['nullable' => false],
                    'Seller ID'
                )->addColumn(
                    'bank_name',
                    Table::TYPE_TEXT,
                    255,
                    ['nullable' => false],
                    'Bank Name'
                )->addColumn(
                    'account_number',
                    Table::TYPE_TEXT,
                    255,
                    ['nullable' => false],
                    'Account Number'
                )->addColumn(
                    'routing_number',
                    Table::TYPE_TEXT,
                    255,
                    ['nullable' => false],
                    'Routing Number'
                )->addColumn(
                    'created_at',
                    Table::TYPE_TIMESTAMP,
                    null,
                    ['nullable' => true, 'default' => Table::TIMESTAMP_INIT],
                    'Created At'
                )->setComment('Seller Payment Info');

                $setup->getConnection()->createTable($table);
            }
        }

        $setup->endSetup();
    }
}
