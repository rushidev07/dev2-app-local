<?php
declare(strict_types=1);

namespace FalcoSense\Search\Controller\Configurable;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Returns the REAL configurable-attribute labels/values and per-variant stock
 * status for a configurable product, sourced directly from Magento's own EAV/
 * inventory data via the standard product APIs.
 *
 * This exists to replace two fragile, previously-relied-upon sources of this
 * same information:
 *   1. The FalcoSense search API's /api/v1/product `configurable_attributes` /
 *      variant `attributes`, which are NOT real attribute data — they are
 *      guessed by splitting the variant's flattened display title on " / "
 *      and heuristically classifying each token as Size vs Color
 *      (ProductDetailController::inferLabel()). Any token that isn't a
 *      recognized size or color word (e.g. numeric waist/inseam values like
 *      "28", "34") falls through to a hardcoded "return 'Color'" default,
 *      producing bogus dimensions like "Color2"/"Color3" for any product
 *      whose configurable attributes aren't literally Color+Size.
 *   2. pub/variant-attrs.php, a standalone script outside Magento's bootstrap
 *      that hardcodes DB credentials, runs directly against the DB with no
 *      auth/ACL, and is blocked by a nginx-level 403 on the production
 *      Magento vhost (works on dev2 only because that domain's blanket HTTP
 *      Basic Auth happens to let the request through first).
 *
 * Frontend callers (see view/frontend/templates/modal/config-modal.phtml)
 * should call this endpoint instead of pub/variant-attrs.php.
 */
class Options extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StockRegistryInterface $stockRegistry,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', true);

        $productId = (int) $this->getRequest()->getParam('product_id');
        $sku       = trim((string) $this->getRequest()->getParam('sku'));

        if (!$productId && $sku === '') {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => 'product_id or sku is required.',
            ]);
        }

        try {
            $product = $productId
                ? $this->productRepository->getById($productId)
                : $this->productRepository->get($sku);
        } catch (NoSuchEntityException $e) {
            return $result->setHttpResponseCode(404)->setData([
                'success' => false,
                'message' => 'Product not found.',
            ]);
        }

        if ($product->getTypeId() !== 'configurable') {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => 'Product is not configurable.',
            ]);
        }

        /** @var \Magento\ConfigurableProduct\Model\Product\Type\Configurable $typeInstance */
        $typeInstance = $product->getTypeInstance();

        // Real attribute code + real frontend label per configurable attribute
        // (e.g. 'waist' => 'WAIST', 'inseam' => 'Inseam'), not a title-parsing guess.
        $attributeMeta = [];
        foreach ($typeInstance->getConfigurableAttributesAsArray($product) as $attribute) {
            $code = $attribute['attribute_code'] ?? null;
            if (!$code) {
                continue;
            }
            $attributeMeta[$code] = (string) ($attribute['frontend_label'] ?: $attribute['store_label'] ?: $code);
        }

        $configurableAttributeValues = array_fill_keys(array_keys($attributeMeta), []);

        $variants = [];
        foreach ($typeInstance->getUsedProducts($product) as $child) {
            $childId    = (int) $child->getId();
            $stockItem  = $this->stockRegistry->getStockItem($childId);
            $attributes = [];

            foreach ($attributeMeta as $code => $label) {
                $value = $child->getAttributeText($code);
                $value = is_array($value) ? implode(', ', $value) : (string) $value;
                if ($value === '') {
                    continue;
                }
                $attributes[$label] = $value;
                if (!in_array($value, $configurableAttributeValues[$code], true)) {
                    $configurableAttributeValues[$code][] = $value;
                }
            }

            $variants[] = [
                'variant_id' => $childId,
                'sku'        => $child->getSku(),
                'attributes' => $attributes,
                'in_stock'   => (bool) $stockItem->getIsInStock(),
                'qty'        => (int) $stockItem->getQty(),
            ];
        }

        $configurableAttributes = [];
        foreach ($attributeMeta as $code => $label) {
            $configurableAttributes[] = [
                'code'   => $code,
                'label'  => $label,
                'values' => $configurableAttributeValues[$code],
            ];
        }

        return $result->setData([
            'success'                 => true,
            'product_id'              => (int) $product->getId(),
            'sku'                     => $product->getSku(),
            'configurable_attributes' => $configurableAttributes,
            'variants'                => $variants,
        ]);
    }
}
