<?php
use Magento\Framework\App\Bootstrap;

require __DIR__ . '/../../../../bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$obj = $bootstrap->getObjectManager();

$state = $obj->get(\Magento\Framework\App\State::class);
$state->setAreaCode('adminhtml');

$productRepository = $obj->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);

$csvFile = 'app/code/Ahy/UpdateProductsCSV/csv-files/categories/product_categories.csv'; 
$categoryToAssign = 3614;

if (!file_exists($csvFile)) {
    exit("CSV file not found at $csvFile\n");
}

// Read file and remove empty lines
$rows = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!$rows) {
    exit("CSV file is empty or cannot be read.\n");
}

// Remove BOM if present
$rows[0] = preg_replace('/^\x{FEFF}/u', '', $rows[0]);

// Get header and remove it from rows
$header = strtolower(trim(array_shift($rows)));
if ($header !== 'product_id') {
    exit("Expected header 'product_id' not found, found '$header' instead.\n");
}

// Loop through rows (each row contains only product ID)
foreach ($rows as $productId) {
    $productId = trim($productId);

    echo "Processing Product ID: $productId\n";

     // Validate product ID

    if (!is_numeric($productId)) {
        echo "Invalid or missing product ID in row. Skipping.\n";
        continue;
    }

    try {
        $product = $productRepository->getById((int)$productId);

        $existingCategoryIds = $product->getCategoryIds();
        if (!in_array($categoryToAssign, $existingCategoryIds)) {
            $existingCategoryIds[] = $categoryToAssign;
            $product->setCategoryIds($existingCategoryIds);
        }

        $productRepository->save($product);
        echo "Assigned category ID $categoryToAssign to Product ID: $productId\n";

    } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
        echo "Product not found for ID: $productId\n";
    } catch (\Exception $e) {
        echo "Error updating Product ID $productId: " . $e->getMessage() . "\n";
    }
}
