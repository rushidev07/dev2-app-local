<?php
use Magento\Framework\App\Bootstrap;

require __DIR__ . '/../../../../bootstrap.php';
$bootstrap = Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

// Set Admin area
$state = $objectManager->get(\Magento\Framework\App\State::class);
try {
    $state->setAreaCode('adminhtml');
} catch (\Exception $e) {}

// Initialize resource and connection
$resource   = $objectManager->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();

// Grazly brand attribute IDs
$brandAttributeId = 268; // attribute id for brand
$brandOptionId    = 63436; // option id for Grazly

echo "Fetching Grazly products...\n";

// Fetch all Grazly product IDs and SKUs
$sql = "
    SELECT DISTINCT cpe.entity_id, cpe.sku
    FROM catalog_product_entity cpe
    JOIN catalog_product_entity_int pei
        ON pei.entity_id = cpe.entity_id
    WHERE pei.attribute_id = ?
      AND pei.value = ?
";
$products = $connection->fetchAll($sql, [$brandAttributeId, $brandOptionId]);

if (empty($products)) {
    die("No Grazly products found.\n");
}
echo "Found " . count($products) . " Grazly products to delete.\n";

// Build comma-separated list of product IDs
$productIds = array_column($products, 'entity_id');
$idsIn = implode(',', $productIds);

// Disable foreign key checks
$connection->query('SET FOREIGN_KEY_CHECKS=0;');

// --- 1) Delete from EAV tables ---
$eavTables = [
    'catalog_product_entity',
    'catalog_product_entity_datetime',
    'catalog_product_entity_decimal',
    'catalog_product_entity_int',
    'catalog_product_entity_text',
    'catalog_product_entity_varchar',
];
foreach ($eavTables as $table) {
    $tableName = $resource->getTableName($table);
    $connection->query("DELETE FROM `$tableName` WHERE entity_id IN ($idsIn)");
}

// --- 2) Delete from stock, website, and category tables ---
$stockWebsiteTables = [
    'catalog_category_product' => 'product_id',
    'catalog_product_website'  => 'product_id',
    'cataloginventory_stock_item' => 'product_id',
    'cataloginventory_stock_status' => 'product_id',
];
foreach ($stockWebsiteTables as $table => $column) {
    $tableName = $resource->getTableName($table);
    $connection->query("DELETE FROM `$tableName` WHERE `$column` IN ($idsIn)");
}

// --- 3) Delete from product relation tables ---

// 3a) Catalog product relation and super links
$relationTables = [
    'catalog_product_relation' => ['child_id', 'parent_id'],
    'catalog_product_super_link' => ['product_id', 'parent_id'],
    'catalog_product_super_attribute' => ['product_id'],
    'catalog_product_bundle_selection' => ['product_id', 'parent_product_id'],
];

foreach ($relationTables as $table => $columns) {
    $tableName = $resource->getTableName($table);
    foreach ($columns as $col) {
        $connection->query("DELETE FROM `$tableName` WHERE `$col` IN ($idsIn)");
    }
}

// 3b) Delete catalog product super attribute (this will cascade to labels)
$tableSuperAttr = $resource->getTableName('catalog_product_super_attribute');
$connection->query("DELETE FROM `$tableSuperAttr` WHERE product_id IN ($idsIn)");

// 3c) Catalog product bundle options & values
$tableBundleOption = $resource->getTableName('catalog_product_bundle_option');
$tableBundleOptionValue = $resource->getTableName('catalog_product_bundle_option_value');

// Delete bundle option values first
$connection->query("
    DELETE bpv
    FROM `$tableBundleOptionValue` AS bpv
    JOIN `$tableBundleOption` AS bpo
      ON bpv.option_id = bpo.option_id
    WHERE bpo.parent_id IN ($idsIn)
");

// Delete bundle options
$connection->query("DELETE FROM `$tableBundleOption` WHERE parent_id IN ($idsIn)");

// --- 4) Delete product URL rewrites ---
$urlTable = $resource->getTableName('url_rewrite');
$connection->query("DELETE FROM `$urlTable` WHERE entity_type = 'product' AND entity_id IN ($idsIn)");

// Re-enable foreign key checks
$connection->query('SET FOREIGN_KEY_CHECKS=1;');

echo "delete complete for Broken Grazly products.\n";