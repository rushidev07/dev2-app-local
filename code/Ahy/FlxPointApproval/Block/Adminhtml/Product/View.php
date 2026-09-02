<?php
declare(strict_types=1);

namespace Ahy\FlxPointApproval\Block\Adminhtml\Product;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Ahy\FlxPointApproval\Model\ApprovalProduct;
use Ahy\FlxPointApproval\Model\ApprovalProductFactory;
use Ahy\FlxPointApproval\Model\ResourceModel\ApprovalVariant\CollectionFactory as VariantCollectionFactory;

class View extends Template
{
    private ApprovalProductFactory    $productFactory;
    private VariantCollectionFactory  $variantCollectionFactory;

    public function __construct(
        Context                  $context,
        ApprovalProductFactory   $productFactory,
        VariantCollectionFactory $variantCollectionFactory,
        array                    $data = []
    ) {
        $this->productFactory           = $productFactory;
        $this->variantCollectionFactory = $variantCollectionFactory;
        parent::__construct($context, $data);
    }

    public function getProduct(): ApprovalProduct
    {
        $id      = (int) $this->getRequest()->getParam('id');
        $product = $this->productFactory->create();
        $product->load($id);
        return $product;
    }

    public function getVariants(string $parentSku): array
    {
        $collection = $this->variantCollectionFactory->create();
        $collection->addFieldToFilter('parent_sku', $parentSku);
        $collection->setOrder('sku', 'ASC');
        return $collection->getItems();
    }

    public function getImageUrl(string $imagePath, string $fallbackUrl): string
    {
        if ($imagePath !== '') {
            return $this->_urlBuilder->getBaseUrl(['_type' => \Magento\Framework\UrlInterface::URL_TYPE_MEDIA])
                . ltrim($imagePath, '/');
        }
        return $fallbackUrl;
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('ahy_flxpoint_approval/product/index');
    }

    public function getApproveUrl(int $entityId): string
    {
        return $this->getUrl('ahy_flxpoint_approval/product/approve', [
            'id'     => $entityId,
            'form_key' => $this->formKey->getFormKey(),
        ]);
    }

    public function getRejectUrl(int $entityId): string
    {
        return $this->getUrl('ahy_flxpoint_approval/product/reject', [
            'id'     => $entityId,
            'form_key' => $this->formKey->getFormKey(),
        ]);
    }

    public function getAdditionalImages(string $imagePaths, string $fallbackUrls): array
    {
        $images = [];
        if ($imagePaths !== '') {
            foreach (explode(' | ', $imagePaths) as $path) {
                $path = trim($path);
                if ($path !== '') {
                    $images[] = $this->getImageUrl($path, '');
                }
            }
        } elseif ($fallbackUrls !== '') {
            foreach (explode(' | ', $fallbackUrls) as $url) {
                $url = trim($url);
                if ($url !== '') {
                    $images[] = $url;
                }
            }
        }
        return $images;
    }
}
