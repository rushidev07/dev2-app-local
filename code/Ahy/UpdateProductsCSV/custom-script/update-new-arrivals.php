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

// Target category ID
$categoryId = 3743;

echo "Assigning products to category ID $categoryId...\n";

// --- 1) Fetch the products matching your selection criteria ---
$sql = "
SELECT cpe.entity_id
FROM catalog_product_entity AS cpe
LEFT JOIN catalog_product_relation AS rel ON cpe.entity_id = rel.child_id
JOIN catalog_product_entity_int AS cpei ON cpe.entity_id = cpei.entity_id
JOIN eav_attribute_option_value AS eaov ON cpei.value = eaov.option_id
WHERE rel.parent_id IS NULL
  AND cpe.sku LIKE '%-1'
  AND cpei.attribute_id = 268
  AND eaov.value IN ('star-batt', 'hiden', 'off the grid')
  AND eaov.store_id = 0
";
$products = $connection->fetchAll($sql);

if (empty($products)) {
    die("No products found matching the criteria.\n");
}

$productIds = array_column($products, 'entity_id');
echo "Found " . count($productIds) . " products to assign.\n";

// --- 2) Assign products to the category ---
$categoryProductTable = $resource->getTableName('catalog_category_product');

// Disable foreign key checks temporarily
$connection->query('SET FOREIGN_KEY_CHECKS=0;');

// Insert products into category (skip duplicates)
foreach ($productIds as $productId) {
    $exists = $connection->fetchOne("
        SELECT COUNT(*) 
        FROM `$categoryProductTable`
        WHERE category_id = ? AND product_id = ?
    ", [$categoryId, $productId]);

    if (!$exists) {
        $connection->insert($categoryProductTable, [
            'category_id' => $categoryId,
            'product_id'  => $productId,
            'position'    => 0
        ]);
    }
}

// Re-enable foreign key checks
$connection->query('SET FOREIGN_KEY_CHECKS=1;');

echo "Products assigned to category ID $categoryId successfully.\n";