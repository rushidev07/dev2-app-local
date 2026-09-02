<?php

namespace Ahy\ThemeCustomization\Block\Override;

use Magento\Catalog\Block\Product\ImageBuilder;
use Magento\Catalog\Helper\Product\Configuration;
use Magento\Catalog\Model\Product\Configuration\Item\ItemResolverInterface;
use Magento\Checkout\Block\Cart\Item\Renderer as OriginalRenderer;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Module\Manager;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Url\Helper\Data as UrlHelper;
use Magento\Framework\View\Element\Message\InterpretationStrategyInterface;
use Magento\Framework\View\Element\Template\Context;
use Ahy\ThemeCustomization\Helper\ProductImage;

class CartItemRenderer extends OriginalRenderer
{
    private ProductImage $productImageHelper;

    public function __construct(
        Context $context,
        Configuration $productConfig,
        Session $checkoutSession,
        ImageBuilder $imageBuilder,
        UrlHelper $urlHelper,
        ManagerInterface $messageManager,
        PriceCurrencyInterface $priceCurrency,
        Manager $moduleManager,
        InterpretationStrategyInterface $messageInterpretationStrategy,
        ProductImage $productImageHelper,
        array $data = [],
        ItemResolverInterface $itemResolver = null
    ) {
        parent::__construct(
            $context,
            $productConfig,
            $checkoutSession,
            $imageBuilder,
            $urlHelper,
            $messageManager,
            $priceCurrency,
            $moduleManager,
            $messageInterpretationStrategy,
            $data,
            $itemResolver ?: ObjectManager::getInstance()->get(ItemResolverInterface::class)
        );
        $this->productImageHelper = $productImageHelper;
    }

    /**
     * CDN image URL for the cart item's own SKU, falling back to the parent
     * product's image (handled inside ProductImage::getUrlBySku()) when the
     * item itself — typically a configurable/bundle variant — has none.
     */
    public function getCdnImageUrl(): ?string
    {
        return $this->productImageHelper->getUrlBySku($this->getItem()->getProduct()->getSku());
    }

    /**
     * Native-image fallback for when getCdnImageUrl() returns null and the template falls
     * back to $block->getImage(...). cart_page_product_thumbnail reads the 'small_image'
     * attribute (view.xml) — not reliably populated for every variant in this catalog, while
     * 'image' is. When that leaves us on Magento's generic placeholder, retry against the
     * parent item's 'image' attribute instead of showing no picture at all.
     */
    public function getImage($product, $imageId, $attributes = [])
    {
        $image = parent::getImage($product, $imageId, $attributes);

        if ($this->isPlaceholder($image->getImageUrl())) {
            $fallback = parent::getImage(
                $this->getItem()->getProduct(),
                $imageId,
                array_merge($attributes, ['type' => 'image'])
            );
            if (!$this->isPlaceholder($fallback->getImageUrl())) {
                return $fallback;
            }
        }

        return $image;
    }

    /**
     * Placeholder asset URLs always contain a '/placeholder/' path segment
     * (Magento_Catalog::images/product/placeholder/{type}.jpg) regardless of
     * image type, so this check doesn't depend on knowing which type resolved.
     */
    private function isPlaceholder(?string $url): bool
    {
        return $url === null || $url === '' || str_contains($url, '/placeholder/');
    }
}
