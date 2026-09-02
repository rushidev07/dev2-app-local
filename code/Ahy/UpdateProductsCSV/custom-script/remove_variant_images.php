<?php
use Magento\Framework\App\Bootstrap;

require __DIR__ . '/../../../../bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$obj = $bootstrap->getObjectManager();

$state = $obj->get(\Magento\Framework\App\State::class);
try {
    $state->setAreaCode('adminhtml');
} catch (\Magento\Framework\Exception\LocalizedException $e) {
    // already set
}

$productRepository = $obj->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
$productCollectionFactory = $obj->get(\Magento\Catalog\Model\ResourceModel\Product\CollectionFactory::class);
$configurableType = $obj->get(\Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable::class);

$storeId = 0;

// Load all configurable (parent) products
$parentCollection = $productCollectionFactory->create()
    ->addAttributeToSelect(['sku', 'name', 'type_id'])
    ->addFieldToFilter('type_id', 'configurable');

echo "🔍 Found " . $parentCollection->getSize() . " configurable products.\n";

foreach ($parentCollection as $parentProduct) {
    $parentSku = $parentProduct->getSku();
    echo "\n📦 Checking parent: $parentSku\n";

    try {
        $childIds = $configurableType->getChildrenIds($parentProduct->getId());
        $childIds = isset($childIds[0]) ? $childIds[0] : [];

        if (empty($childIds)) {
            echo "⏩ No variants found for $parentSku, skipping.\n";
            continue;
        }

        echo "🧩 Found " . count($childIds) . " variants for $parentSku\n";

        foreach ($childIds as $childId) {
            try {
                $childProduct = $productRepository->getById($childId, false, $storeId);
                $childSku = $childProduct->getSku();

                // Only remove images if SKU starts with 'off-'
                if (stripos($childSku, 'off-') !== 0) {
                    echo "  ⏩ Variant $childSku does not start with 'off-', skipping.\n";
                    continue;
                }

                echo "  🔧 Removing images for variant: $childSku ... ";

                $childProduct->setMediaGalleryEntries([]);
                $productRepository->save($childProduct);
                echo "✅ images removed.\n";

            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                echo "❌ Variant not found (ID: $childId)\n";
            } catch (\Exception $e) {
                echo "❌ Error removing images for variant $childSku: " . $e->getMessage() . "\n";
            }
        }

    } catch (\Exception $e) {
        echo "❌ Error processing parent $parentSku: " . $e->getMessage() . "\n";
    }
}

echo "\n🎯 Script complete!\n";
