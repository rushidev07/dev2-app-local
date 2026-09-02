<?php

use Magento\Framework\App\Bootstrap;
use Magento\Catalog\Model\Product\Attribute\Source\Status;

require __DIR__ . '/../../../../bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

$state = $objectManager->get(\Magento\Framework\App\State::class);
try {
    $state->setAreaCode('adminhtml');
} catch (\Exception $e) {}

$productRepository = $objectManager->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);

$resource = $objectManager->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();

echo "Fetching Marketplace products created on 22 July 2021...\n";

$sql = "
SELECT cpe.sku
FROM marketplace_product AS mp
JOIN catalog_product_entity AS cpe
    ON mp.mageproduct_id = cpe.entity_id
WHERE DATE(mp.created_at) = '2021-07-22';
";

$products = $connection->fetchAll($sql);

if (empty($products)) {
    die("No Marketplace products found for 22 July 2021.\n");
}

echo "Found " . count($products) . " products. Disabling them...\n";

$disabledCount = 0;
$disabledSkus  = [];

foreach ($products as $row) {
    $sku = $row['sku'];
    try {
        $product = $productRepository->get($sku);
        $product->setStatus(Status::STATUS_DISABLED);
        $productRepository->save($product);
        $disabledCount++;
        $disabledSkus[] = $sku;
        echo "Disabled SKU: {$sku}\n";
    } catch (\Exception $e) {
        echo "ERROR disabling SKU {$sku}: " . $e->getMessage() . "\n";
    }
}

echo "\n=============================================\n";
echo "Successfully disabled {$disabledCount} products.\n";

echo "Disabled SKUs: [" . implode(", ", $disabledSkus) . "]\n";
echo "=============================================\n";
