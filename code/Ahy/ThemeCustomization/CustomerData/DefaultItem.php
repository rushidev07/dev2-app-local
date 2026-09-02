<?php
namespace Ahy\ThemeCustomization\CustomerData;

class DefaultItem extends \Magento\Checkout\CustomerData\DefaultItem
{
    private const FALLBACK_IMAGE = 'https://static.everest.com/media/.thumbswysiwyg/everest-logo_2_.png';

    protected function doGetItemData()
    {
        // Uses Magento's native mini_cart_product_thumbnail cache (parent::doGetItemData()) —
        // previously overwritten here with a falcosense CDN URL; reverted to the native cache
        // so mini-cart shares the same resized/cached images as the rest of the site.
        $data = parent::doGetItemData();

        // mini_cart_product_thumbnail reads the 'thumbnail' attribute (view.xml), which isn't
        // reliably populated in this catalog — 'image' is. When that leaves us on Magento's
        // generic placeholder, re-resolve from the parent product's 'image' attribute instead
        // of showing no picture at all.
        if ($this->isPlaceholder($data['product_image']['src'] ?? '')) {
            $parentProduct = $this->item->getProduct();
            $fallbackImage = $this->imageHelper->init($parentProduct, 'mini_cart_product_thumbnail', ['type' => 'image']);
            $fallbackUrl = $fallbackImage->getUrl();
            if (!$this->isPlaceholder($fallbackUrl)) {
                $data['product_image']['src'] = $fallbackUrl;
                $data['product_image']['alt'] = $fallbackImage->getLabel();
            }
        }

        // Parent's array has no fallback_src; the template's @error handler needs a real one.
        $data['product_image']['fallback_src'] = self::FALLBACK_IMAGE;

        return $data;
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
