<?php
declare(strict_types=1);

namespace Ahy\ThemeCustomization\Controller\Index;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Catalog\Model\ProductFactory;

/**
 * Resolves the Magento super_attribute[attributeId] => optionId map for a configurable
 * parent + one of its child SKUs, so callers that only know the FalcoSense variant id
 * (search modal, sliders) can still add-to-cart the same way PDP does -- against the
 * parent product with the real configurable options -- instead of adding the child
 * simple product directly as its own quote item.
 */
class ResolveSuperAttribute extends Action
{
    public function __construct(
        Context $context,
        private readonly ProductFactory $productFactory,
        private readonly JsonFactory $resultJsonFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $resultJson = $this->resultJsonFactory->create();

        $parentId = (int) $this->getRequest()->getParam('parent_id');
        $childId  = (int) $this->getRequest()->getParam('child_id');

        if (!$parentId || !$childId) {
            return $resultJson->setData([
                'success' => false,
                'message' => 'parent_id and child_id are required',
            ]);
        }

        $parent = $this->productFactory->create()->load($parentId);

        if (!$parent->getId() || $parent->getTypeId() !== 'configurable') {
            return $resultJson->setData([
                'success' => false,
                'message' => 'parent_id is not a configurable product',
            ]);
        }

        $child = $this->productFactory->create()->load($childId);

        if (!$child->getId()) {
            return $resultJson->setData([
                'success' => false,
                'message' => 'child_id is not a valid product',
            ]);
        }

        $configurableAttributes = $parent->getTypeInstance()->getConfigurableAttributesAsArray($parent);

        $superAttribute = [];
        foreach ($configurableAttributes as $attribute) {
            $attributeId = (string) ($attribute['attribute_id'] ?? '');
            $code        = $attribute['attribute_code'] ?? null;

            if ($attributeId === '' || !$code) {
                continue;
            }

            $optionId = $child->getData($code);

            if ($optionId === null || $optionId === '') {
                return $resultJson->setData([
                    'success' => false,
                    'message' => "Variant is missing a value for attribute '{$code}'",
                ]);
            }

            $superAttribute[$attributeId] = (string) $optionId;
        }

        if (empty($superAttribute)) {
            return $resultJson->setData([
                'success' => false,
                'message' => 'Could not resolve configurable options for this variant',
            ]);
        }

        return $resultJson->setData([
            'success'         => true,
            'parent_id'       => $parent->getId(),
            'super_attribute' => $superAttribute,
        ]);
    }
}
