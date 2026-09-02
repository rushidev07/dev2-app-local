<?php
use Magento\Framework\App\Bootstrap;

require __DIR__ . '/../../../../bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$obj = $bootstrap->getObjectManager();

// Set area code
$state = $obj->get(\Magento\Framework\App\State::class);
try {
    $state->setAreaCode('adminhtml');
} catch (\Magento\Framework\Exception\LocalizedException $e) {
    // Area code already set
}

$connection = $obj->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
$productRepository = $obj->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
$productResource = $obj->get(\Magento\Catalog\Model\ResourceModel\Product::class);

// Configs
$csvFile = 'app/code/Ahy/UpdateProductsCSV/csv-files/categories/bill-hicks-upc.csv'; 
$attributeCode = 'age_verification_required';
$valueToSet = 1; // 1 = Yes
$storeId = 0;

// Check CSV file exists
if (!file_exists($csvFile)) {
    exit("❌ CSV file not found at $csvFile\n");
}

// Read CSV
$rows = array_map('str_getcsv', file($csvFile));
$header = array_map('strtolower', array_map('trim', array_shift($rows)));
$upcIndex = array_search('upc', $header);
if ($upcIndex === false) {
    exit("❌ CSV does not contain 'upc' column\n");
}

// Fetch all existing UPCs and map them to SKUs
$sql = "SELECT v.value AS upc_number, e.sku
        FROM catalog_product_entity_varchar v
        JOIN catalog_product_entity e ON v.entity_id = e.entity_id
        WHERE v.attribute_id = (
            SELECT attribute_id
            FROM eav_attribute
            WHERE attribute_code = 'upc_number'
              AND entity_type_id = (
                  SELECT entity_type_id
                  FROM eav_entity_type
                  WHERE entity_type_code = 'catalog_product'
              )
        )
        AND v.store_id = 0
        AND v.value IS NOT NULL";
$results = $connection->fetchAll($sql);

// Build UPC -> SKU map
$upcToSku = [];
foreach ($results as $rowDb) {
    $upcToSku[trim($rowDb['upc_number'])] = $rowDb['sku'];
}

echo "ℹ️ Total UPCs on site: " . count($upcToSku) . "\n";

// Prepare non-existing UPC log
$notFound = [];

// Process CSV rows in batches
$batchSize = 500;
$totalRows = count($rows);
$processed = 0;

foreach ($rows as $row) {
    $upc = trim($row[$upcIndex]);

    if (isset($upcToSku[$upc])) {
        $sku = $upcToSku[$upc];
        try {
            $product = $productRepository->get($sku, false, $storeId);
            $product->setData($attributeCode, $valueToSet);
            $productResource->saveAttribute($product, $attributeCode); // save only this attribute
            echo "✅ Updated SKU $sku for UPC $upc: $attributeCode set to Yes\n";
        } catch (\Exception $e) {
            echo "❌ Error updating SKU $sku for UPC $upc: " . $e->getMessage() . "\n";
            $notFound[] = [$upc];
        }
    } else {
        echo "⚠️ UPC $upc not found on site. Skipping.\n";
        $notFound[] = [$upc];
    }

    $processed++;
    if ($processed % $batchSize == 0) {
        echo "ℹ️ Processed $processed / $totalRows rows\n";
    }
}

// Write non-existing UPCs to CSV
if ($notFound) {
    $fp = fopen('upcs_not_found.csv', 'w');
    fputcsv($fp, ['upc_number']);
    foreach ($notFound as $nf) {
        fputcsv($fp, $nf);
    }
    fclose($fp);
    echo "⚠️ Non-existing UPCs exported to upcs_not_found.csv\n";
}

echo "✅ Done. Processed $processed rows.\n";
