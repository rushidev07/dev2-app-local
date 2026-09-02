<?php
use Magento\Framework\App\Bootstrap;

require __DIR__ . '/../../../../bootstrap.php';

$params = $_SERVER;
$bootstrap = Bootstrap::create(BP, $params);
$obj = $bootstrap->getObjectManager();

$state = $obj->get(\Magento\Framework\App\State::class);
try {
    $state->setAreaCode('adminhtml');
} catch (\Magento\Framework\Exception\LocalizedException $e) {
    // Area code already set
}

$categoryCollectionFactory = $obj->get(\Magento\Catalog\Model\ResourceModel\Category\CollectionFactory::class);

// Load category collection
$collection = $categoryCollectionFactory->create()
    ->addAttributeToSelect(['name', 'is_active', 'include_in_menu', 'url_key', 'url_path']) // make sure url_path is loaded
    ->addFieldToFilter('level', ['gteq' => 2]) // skip root & default categories
    ->addFieldToFilter('is_active', 1); // only active categories

// Build categories indexed by ID
$categories = [];
foreach ($collection as $category) {
    $categories[$category->getId()] = [
        'category_id' => $category->getId(),
        'name' => $category->getName(),
        'url' => $category->getUrlPath() ?: $category->getUrlKey(), // fallback if needed
        'status' => 'Active',
        'include_in_menu' => (bool)$category->getIncludeInMenu(),
        'children' => [],
        '_parent_id' => $category->getParentId(), // used internally only
    ];
}

// Build the nested category tree
$tree = [];
foreach ($categories as $id => &$category) {
    $parentId = $category['_parent_id'];
    unset($category['_parent_id']); // remove internal use key from output

    if (isset($categories[$parentId])) {
        $categories[$parentId]['children'][] = &$category;
    } else {
        $tree[] = &$category;
    }
}

// Save JSON to pub/categories.json
$outputPath = BP . '/pub/categories.json';
file_put_contents($outputPath, json_encode($tree, JSON_PRETTY_PRINT));

echo "Exported categories to $outputPath\n";
