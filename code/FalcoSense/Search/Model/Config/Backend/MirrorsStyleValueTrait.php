<?php
declare(strict_types=1);

namespace FalcoSense\Search\Model\Config\Backend;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;

/**
 * Shared by every system.xml backend_model whose path is
 * "smart_search/<component_code>/<attribute_code>": resolves the attribute
 * row and mirrors the saved value into falcosense_style_value, alongside
 * whatever the field's own parent backend model persists (e.g. core_config_data).
 */
trait MirrorsStyleValueTrait
{
    private function resolveAttribute(ResourceConnection $resourceConnection, string $path): array
    {
        [, $componentCode, $attributeCode] = explode('/', $path);

        $connection = $resourceConnection->getConnection();
        $attribute = $connection->fetchRow(
            $connection->select()
                ->from(['a' => $resourceConnection->getTableName('falcosense_style_attribute')], ['attribute_id', 'input_type'])
                ->join(
                    ['c' => $resourceConnection->getTableName('falcosense_style_component')],
                    'c.component_id = a.component_id',
                    []
                )
                ->where('c.code = ?', $componentCode)
                ->where('a.code = ?', $attributeCode)
        );

        if (!$attribute) {
            throw new LocalizedException(
                __('No style attribute registered for "%1/%2".', $componentCode, $attributeCode)
            );
        }

        return $attribute;
    }

    private function mirrorStyleValue(
        ResourceConnection $resourceConnection,
        int $attributeId,
        string $scope,
        int $scopeId,
        string $value
    ): void {
        $resourceConnection->getConnection()->insertOnDuplicate(
            $resourceConnection->getTableName('falcosense_style_value'),
            [
                'attribute_id' => $attributeId,
                'scope' => $scope,
                'scope_id' => $scopeId,
                'value' => $value,
            ],
            ['value']
        );
    }
}
