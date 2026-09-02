<?php
use Magento\Framework\App\Bootstrap;

require __DIR__ . '/../../../../bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$obj = $bootstrap->getObjectManager();
$outputDir = BP . '/pub';
$outputFile = $outputDir . '/categories.json';

$state = $obj->get(\Magento\Framework\App\State::class);
try {
    $state->setAreaCode('adminhtml');
} catch (\Magento\Framework\Exception\LocalizedException $e) {
    // Already set
}

$categoryRepository = $obj->get(\Magento\Catalog\Api\CategoryRepositoryInterface::class);
$categoryCollectionFactory = $obj->get(\Magento\Catalog\Model\ResourceModel\Category\CollectionFactory::class);
$url = $obj->get(\Magento\Framework\UrlInterface::class);

// Recursive function to format categories
function getFormattedCategoryData($category, $categoryCollectionFactory)
{
    if (!$category->getIsActive()) {
        $status = "Inactive";
    } else {
        $status = "Active";
    }

    $data = [
        'category_id' => $category->getId(),
        'name' => $category->getName(),
        'url' => $category->getUrlKey(),
        'status' => $status,
        'include_in_menu' => (bool)$category->getIncludeInMenu(),
    ];

    // Fetch children
    $childrenData = [];
    $childCategories = $categoryCollectionFactory->create()
        ->addAttributeToSelect(['name', 'is_active', 'url_key', 'include_in_menu'])
        ->addFieldToFilter('parent_id', $category->getId())
        ->addIsActiveFilter();

    foreach ($childCategories as $childCategory) {
        $childrenData[] = getFormattedCategoryData($childCategory, $categoryCollectionFactory);
    }

    if (!empty($childrenData)) {
        $data['children'] = $childrenData;
    }

    return $data;
}

// Get root categories under Default (usually root ID 2, children like ID 3+)
$rootCategoryId = 2;
$rootCategories = $categoryCollectionFactory->create()
    ->addAttributeToSelect(['name', 'is_active', 'url_key', 'include_in_menu'])
    ->addFieldToFilter('parent_id', $rootCategoryId);

$result = [];

foreach ($rootCategories as $category) {
    $result[] = getFormattedCategoryData($category, $categoryCollectionFactory);
}
if (!file_exists($outputDir)) {
    echo "File does not exists. Creating a file categories.json\n";
    mkdir($outputDir, 0755, true);
    file_put_contents($outputFile, json_encode($result, JSON_PRETTY_PRINT));
}
else{
    echo "File does exists. Writing in the file\n";
    file_put_contents($outputFile, json_encode($result, JSON_PRETTY_PRINT));
}

echo "Exported categories to pub/categories.json\n";
