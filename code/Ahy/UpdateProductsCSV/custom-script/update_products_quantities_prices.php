<?php
use Magento\Framework\App\Bootstrap;

require __DIR__ . '/../../../../bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$obj = $bootstrap->getObjectManager();

/** Set area code */
try {
    $state = $obj->get(\Magento\Framework\App\State::class);
    $state->setAreaCode('adminhtml');
} catch (\Magento\Framework\Exception\LocalizedException $e) {
    // Area code already set, continue
}

$productRepository = $obj->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);

/** Stock Registry */
$stockRegistry = $obj->get(\Magento\CatalogInventory\Api\StockRegistryInterface::class);

/** CSV file */
$csvFile = 'app/code/Ahy/UpdateProductsCSV/csv-files/quantities/duluth-product-quantities.csv';

if (!file_exists($csvFile)) {
    exit("CSV file not found.\n");
}

$rows   = array_map('str_getcsv', file($csvFile));
$header = array_shift($rows);

// Clean headers (remove BOM and whitespace)
if (isset($header[0])) {
    $header[0] = str_replace("\xEF\xBB\xBF", '', $header[0]);
}
$header = array_map('trim', $header);

echo "Headers detected: " . implode(', ', $header) . "\n";

$updatedSkus   = [];
$updatedCount  = 0;
$skippedCount  = 0;
$errorCount    = 0;

foreach ($rows as $row) {
    if (count($row) !== count($header)) {
        // Try to pad or slice if mismatch, or just skip
        if (count($row) < count($header)) {
            $row = array_pad($row, count($header), null);
        } else {
             $row = array_slice($row, 0, count($header));
        }
    }
    
    $data = array_combine($header, $row);

    if (empty($data['sku'])) {
        $skippedCount++;
        continue;
    }

    $sku = trim($data['sku']);

    if (in_array($sku, $updatedSkus)) {
        echo "Skipping duplicate SKU: $sku\n";
        $skippedCount++;
        continue;
    }

    $quantity = isset($data['quantity']) && $data['quantity'] !== ''
        ? (float) $data['quantity']
        : null;

    if ($quantity === null) {
        $skippedCount++;
        continue;
    }

    try {
        $stockItem = $stockRegistry->getStockItemBySku($sku);
        $stockItem->setManageStock(true);
        $stockItem->setUseConfigManageStock(false); // Force item level config
        $stockItem->setQty($quantity);
        $stockItem->setIsInStock($quantity > 0);

        $stockRegistry->updateStockItemBySku($sku, $stockItem);

        echo "Processed SKU: $sku (Qty: $quantity)\n";

        $updatedSkus[] = $sku;
        $updatedCount++;

    } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
        echo "SKU not found: $sku\n";
    } catch (Exception $e) {
        echo "Error updating SKU: $sku — " . $e->getMessage() . "\n";
    }
}

/** Summary */
echo "\n===== Duluth Update Summary =====\n";
echo "Processed products : $updatedCount\n";
echo "Skipped rows       : $skippedCount\n";
echo "Errors             : $errorCount\n";
echo "==========================\n";
