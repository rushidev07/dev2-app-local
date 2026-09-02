<?php
declare(strict_types=1);

namespace FalcoSense\Search\Model\Plp;

/**
 * One product card's data, resolved and absolute — no further lookups needed
 * by the SSR card renderer or by Alpine. Field names and toArray() shape
 * intentionally match exactly what search/results.phtml's Alpine component
 * already expects from a live /api/v1/products response (product_id, sku,
 * name, url_key, image, price, special_price, brand, type, variants,
 * in_stock) — the embedded SSR payload and a live fetch() response are the
 * same shape, so Alpine needs zero new mapping logic to consume either one.
 */
final class PlpItem
{
    public function __construct(
        public readonly int $productId,
        public readonly string $sku,
        public readonly string $name,
        public readonly string $urlKey,
        public readonly string $imageUrl,
        public readonly ?float $price,
        public readonly ?float $specialPrice = null,
        public readonly ?string $brand = null,
        public readonly string $type = 'simple',
        public readonly bool $inStock = true,
        public readonly array $variants = [],
    ) {
    }

    public function effectivePrice(): ?float
    {
        if ($this->specialPrice !== null && $this->price !== null && $this->specialPrice < $this->price) {
            return $this->specialPrice;
        }

        return $this->price;
    }

    public function hasDiscount(): bool
    {
        return $this->specialPrice !== null
            && $this->price !== null
            && $this->specialPrice < $this->price;
    }

    public function isConfigurable(): bool
    {
        return $this->type === 'configurable';
    }

    public function toArray(): array
    {
        return [
            'product_id'    => $this->productId,
            'sku'           => $this->sku,
            'name'          => $this->name,
            'url_key'       => $this->urlKey,
            'image'         => $this->imageUrl,
            'price'         => $this->price,
            'special_price' => $this->specialPrice,
            'brand'         => $this->brand,
            'type'          => $this->type,
            'in_stock'      => $this->inStock,
            'variants'      => $this->variants,
        ];
    }
}
