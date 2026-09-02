<?php

use Magento\Framework\App\Bootstrap;

require __DIR__ . '/../../../../bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

// Set area
$state = $objectManager->get(\Magento\Framework\App\State::class);
try {
    $state->setAreaCode('adminhtml');
} catch (\Exception $e) {}

$productRepository = $objectManager->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
$resource = $objectManager->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();

// $categoryToAssign = 3742;
// $discountPercent  = 75;  

// echo "\nUsing Discount: {$discountPercent}% OFF\n";
echo "\nFetching DSG products...\n";
$sql = "
SELECT
    cpe.entity_id AS product_id,
    cpe.sku,
    name.value AS product_name,
    price.value AS price,
    special_price.value AS special_price,
    qty.qty AS quantity,
    cat_varchar.value AS category_name
FROM marketplace_userdata AS mu
JOIN marketplace_product AS mp ON mu.seller_id = mp.seller_id
JOIN catalog_product_entity AS cpe ON mp.mageproduct_id = cpe.entity_id

/* CATEGORY FILTER */
    JOIN catalog_category_product AS ccp
        ON ccp.product_id = cpe.entity_id
    JOIN catalog_category_entity AS cat
        ON cat.entity_id = ccp.category_id
    JOIN catalog_category_entity_varchar AS cat_varchar
        ON cat_varchar.entity_id = cat.entity_id
        AND cat_varchar.attribute_id = (
            SELECT attribute_id FROM eav_attribute
            WHERE attribute_code = 'name'
                AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_category')
        )
        AND cat_varchar.store_id = 0

    /* PRODUCT NAME */
    LEFT JOIN catalog_product_entity_varchar AS name
        ON name.entity_id = cpe.entity_id
        AND name.attribute_id = (
            SELECT attribute_id FROM eav_attribute
            WHERE attribute_code = 'name'
                AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_product')
        )
        AND name.store_id = 0

    /* PRICE */
    LEFT JOIN catalog_product_entity_decimal AS price
        ON price.entity_id = cpe.entity_id
        AND price.attribute_id = (
            SELECT attribute_id FROM eav_attribute
            WHERE attribute_code = 'price'
                AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_product')
        )
        AND price.store_id = 0

    /* SPECIAL PRICE */
    LEFT JOIN catalog_product_entity_decimal AS special_price
        ON special_price.entity_id = cpe.entity_id
        AND special_price.attribute_id = (
            SELECT attribute_id FROM eav_attribute
            WHERE attribute_code = 'special_price'
                AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_product')
        )
        AND special_price.store_id = 0

    /* QTY */
    LEFT JOIN cataloginventory_stock_item AS qty
        ON qty.product_id = cpe.entity_id

    WHERE
        mu.shop_title LIKE '%The Everest Collection%' AND cat_varchar.value like '%Decals & Patches%';
    ";

$products = $connection->fetchAll($sql);

if (empty($products)) {
    die("No products found.\n");
}

echo "Found " . count($products) . " products.\n\n";

$updated = 0;

// ===========================
// APPLY DISCOUNT & ASSIGN CATEGORY
// ===========================
foreach ($products as $row) {

    $sku        = $row['sku'];
    $productId  = (int)$row['product_id'];
    $price      = (float)$row['price'];

    if ($price <= 0) {
        echo "Skipping SKU {$sku} (invalid price)\n";
        continue;
    }

    // ----------------------------------------
    // BASIC DISCOUNT FORMULA:
    // new price = price - (price × discount%)
    // ----------------------------------------
    $discountAmount   = $price * ($discountPercent / 100);
    $newSpecialPrice  = round($price - $discountAmount, 2);

    echo "Updating SKU {$sku}\n";
    echo "Original Price: {$price}\n";
    echo "Discount Amount ({$discountPercent}%): -{$discountAmount}\n";
    echo "New Special Price: {$newSpecialPrice}\n";

    try {
        $product = $productRepository->get($sku);

        // Set special price
        $product->setSpecialPrice(null);
        $product->setSpecialFromDate(null);
        $product->setSpecialToDate(null);

        // Optional: date range
        // $product->setSpecialFromDate('2025-11-28');
        // $product->setSpecialToDate('2025-12-02');

        // Assign category
        // $existingCategoryIds = $product->getCategoryIds();

        // if (!in_array($categoryToAssign, $existingCategoryIds)) {
        //     $existingCategoryIds[] = $categoryToAssign;
        //     $product->setCategoryIds($existingCategoryIds);
        //     echo "Category {$categoryToAssign} assigned.\n";
        // } else {
        //     echo "Category {$categoryToAssign} already assigned.\n";
        // }

        // Save product
        $productRepository->save($product);
        $updated++;

    } catch (\Exception $e) {
        echo "ERROR updating SKU {$sku}: " . $e->getMessage() . "\n";
    }

    echo "----------------------------------------\n";
}

echo "\n=============================================\n";
echo "Successfully updated {$updated} products.\n";
echo "Special prices removed.\n";
echo "=============================================\n";
