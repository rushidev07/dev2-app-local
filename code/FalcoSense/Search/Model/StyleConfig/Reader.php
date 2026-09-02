<?php
declare(strict_types=1);

namespace FalcoSense\Search\Model\StyleConfig;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Resolves an effective style attribute value with store -> website -> default scope fallback.
 */
class Reader
{
    /** Must match the <upload_dir> path configured for image-type style attributes in system.xml. */
    private const IMAGE_MEDIA_PATH = 'falcosense/icons';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly Filesystem $filesystem
    ) {
    }

    public function getValue(string $componentCode, string $attributeCode): ?string
    {
        $connection = $this->resourceConnection->getConnection();

        $attribute = $connection->fetchRow(
            $connection->select()
                ->from(['a' => $this->resourceConnection->getTableName('falcosense_style_attribute')], ['attribute_id', 'default_value'])
                ->join(
                    ['c' => $this->resourceConnection->getTableName('falcosense_style_component')],
                    'c.component_id = a.component_id',
                    []
                )
                ->where('c.code = ?', $componentCode)
                ->where('a.code = ?', $attributeCode)
        );

        if (!$attribute) {
            return null;
        }

        foreach ($this->getScopeCandidates() as [$scope, $scopeId]) {
            $value = $connection->fetchOne(
                $connection->select()
                    ->from($this->resourceConnection->getTableName('falcosense_style_value'), 'value')
                    ->where('attribute_id = ?', $attribute['attribute_id'])
                    ->where('scope = ?', $scope)
                    ->where('scope_id = ?', $scopeId)
            );

            if ($value !== false && $value !== null) {
                return (string)$value;
            }
        }

        return $attribute['default_value'];
    }

    /**
     * Resolves an image-type style attribute to a full, browser-loadable media URL.
     * Returns null when no image is configured, so callers can fall back to a built-in default.
     */
    public function getImageUrl(string $componentCode, string $attributeCode): ?string
    {
        $filename = $this->getValue($componentCode, $attributeCode);
        if (!$filename) {
            return null;
        }

        // Deliberately URL_TYPE_WEB, not URL_TYPE_MEDIA: this environment runs a plugin
        // (Ahy\ThemeCustomization\Plugin\MediaBaseUrlPlugin) that rewrites every MEDIA-type
        // base URL to production so dev/staging can reuse production's shared product image
        // library. That plugin only distinguishes by URL type, so it can't tell our
        // module-owned, locally-uploaded icon apart from a shared catalog image. Building from
        // WEB + the actual media directory URI (replicating Store::getBaseUrl()'s own fallback
        // when no media override is configured) sidesteps the rewrite entirely, keeping the
        // icon on whichever host it was actually uploaded to.
        $baseUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB)
            . $this->filesystem->getUri(DirectoryList::MEDIA);

        return rtrim($baseUrl, '/') . '/' . self::IMAGE_MEDIA_PATH . '/' . ltrim($filename, '/');
    }

    /**
     * @return array<int, array{0: string, 1: int}>
     */
    private function getScopeCandidates(): array
    {
        $store = $this->storeManager->getStore();

        return [
            [ScopeInterface::SCOPE_STORES, (int)$store->getId()],
            [ScopeInterface::SCOPE_WEBSITES, (int)$store->getWebsiteId()],
            ['default', 0],
        ];
    }
}
