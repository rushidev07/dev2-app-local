<?php
namespace Ahy\ThemeCustomization\Helper;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\UrlInterface;

class ProductImage
{
    // FalcoSense's own compressed-image cache — sharded by the bare filename's
    // first two characters, same convention as the rest of the site. Was
    // previously hardcoded to the legacy Klevu CDN path.
    private const CDN_BASE = 'https://static.everest.com/media/falcosense/800x800';

    private ResourceConnection $resource;
    private StoreManagerInterface $storeManager;

    public function __construct(
        ResourceConnection $resource,
        StoreManagerInterface $storeManager
    ) {
        $this->resource     = $resource;
        $this->storeManager = $storeManager;
    }

    public function getUrlBySku(string $sku): ?string
    {
        $path = $this->getImagePathBySku($sku);
        if (!$path) {
            return null;
        }
        $filename = basename($path);
        if (strlen($filename) < 2) {
            return null;
        }
        return self::CDN_BASE . '/' . $filename[0] . '/' . $filename[1] . '/' . $filename;
    }

    public function getMediaUrlBySku(string $sku): ?string
    {
        $path = $this->getImagePathBySku($sku);
        if (!$path) {
            return null;
        }
        $mediaUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        return $mediaUrl . 'catalog/product' . $path;
    }

    private function getImagePathBySku(string $sku): ?string
    {
        $conn = $this->resource->getConnection();

        $select = $conn->select()
            ->from(['p' => $conn->getTableName('catalog_product_entity')], ['entity_id'])
            ->join(
                ['a' => $conn->getTableName('eav_attribute')],
                "a.entity_type_id = 4 AND a.attribute_code = 'image'",
                []
            )
            ->join(
                ['v' => $conn->getTableName('catalog_product_entity_varchar')],
                'v.attribute_id = a.attribute_id AND v.entity_id = p.entity_id AND v.store_id = 0',
                ['value']
            )
            ->where('p.sku = ?', $sku)
            ->limit(1);

        $row = $conn->fetchRow($select);

        if ($row && !empty($row['value']) && $row['value'] !== 'no_selection') {
            return $row['value'];
        }

        if ($row) {
            $parentSelect = $conn->select()
                ->from(['r' => $conn->getTableName('catalog_product_relation')], [])
                ->join(
                    ['pp' => $conn->getTableName('catalog_product_entity')],
                    'pp.entity_id = r.parent_id',
                    []
                )
                ->join(
                    ['a' => $conn->getTableName('eav_attribute')],
                    "a.entity_type_id = 4 AND a.attribute_code = 'image'",
                    []
                )
                ->join(
                    ['v' => $conn->getTableName('catalog_product_entity_varchar')],
                    'v.attribute_id = a.attribute_id AND v.entity_id = pp.entity_id AND v.store_id = 0',
                    ['value']
                )
                ->where('r.child_id = ?', $row['entity_id'])
                ->where('v.value != ?', 'no_selection')
                ->where('v.value IS NOT NULL')
                ->limit(1);

            $parentPath = $conn->fetchOne($parentSelect);
            if ($parentPath) {
                return $parentPath;
            }
        }

        return null;
    }
}
