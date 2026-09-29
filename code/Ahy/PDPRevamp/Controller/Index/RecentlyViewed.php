<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Controller\Index;

use Ahy\PDPRevamp\Service\YotpoClient;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Webkul\Marketplace\Helper\Data as MarketplaceHelper;

/**
 * Single-call data source for the "Recently Viewed" carousel: name, image,
 * url, price, rating and brand for a list of SKUs, all resolved server
 * side. Replaces what used to be two separate round trips from
 * recently-viewed.phtml - a GraphQL call for name/image/price/rating, plus
 * a call to (the now-removed) ProductBrands controller just for the brand
 * label - which doubled the number of things that could fail per page load
 * and left GraphQL's own catalog-visibility filtering as a silent,
 * unrelated reason a stored SKU could vanish from the list.
 *
 * A SKU this can't resolve (deleted, disabled) is simply absent from the
 * response - recently-viewed.phtml already drops any entry it doesn't get
 * data back for, same as before.
 */
class RecentlyViewed implements HttpGetActionInterface
{
    private Context $context;
    private JsonFactory $resultJsonFactory;
    private ProductRepositoryInterface $productRepository;
    private MarketplaceHelper $marketplaceHelper;
    private ImageHelper $imageHelper;
    private YotpoClient $yotpoClient;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        ProductRepositoryInterface $productRepository,
        MarketplaceHelper $marketplaceHelper,
        ImageHelper $imageHelper,
        YotpoClient $yotpoClient
    ) {
        $this->context = $context;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->productRepository = $productRepository;
        $this->marketplaceHelper = $marketplaceHelper;
        $this->imageHelper = $imageHelper;
        $this->yotpoClient = $yotpoClient;
    }

    public function execute(): ResultInterface
    {
        $request = $this->context->getRequest();
        $skus = $request->getParam('skus', []);
        $result = $this->resultJsonFactory->create();

        if (!is_array($skus) || !$skus) {
            return $result->setData([]);
        }

        $items = [];
        // Same 20-item ceiling as the old ProductBrands endpoint - the
        // stored history itself is already capped at 20 entries client-side.
        foreach (array_slice($skus, 0, 20) as $sku) {
            $sku = (string) $sku;
            if ($sku === '') {
                continue;
            }

            try {
                $product = $this->productRepository->get($sku, false, null, true);
            } catch (NoSuchEntityException $e) {
                continue;
            }

            if ((int) $product->getStatus() !== Status::STATUS_ENABLED) {
                continue;
            }

            $items[$sku] = $this->buildItem($product);
        }

        return $result->setData($items);
    }

    /**
     * @return array{name: string, url: string, image: string, price: float, ratingPercent: float, brand: string}
     */
    private function buildItem(Product $product): array
    {
        $bottomline = $this->yotpoClient->getBottomline((int) $product->getId());

        return [
            'name' => (string) $product->getName(),
            'url' => $product->getProductUrl(),
            'image' => $this->imageHelper->init($product, 'product_small_image')->getUrl(),
            'price' => (float) $product->getPriceInfo()->getPrice('final_price')->getValue(),
            'ratingPercent' => $bottomline['average_score'] > 0
                ? round($bottomline['average_score'] / 5 * 100)
                : 0,
            'brand' => $this->resolveBrandLabel($product),
        ];
    }

    /**
     * Same fallback order as the removed ProductBrands controller: the
     * product_brand attribute option label if set, otherwise the
     * marketplace seller's shop name, otherwise '' - a product with
     * neither gets no label rather than a misleading default.
     */
    private function resolveBrandLabel(Product $product): string
    {
        $brand = (string) $product->getAttributeText('product_brand');
        if ($brand !== '' && $brand !== 'No') {
            return $brand;
        }

        $seller = $this->marketplaceHelper->getSellerProductDataByProductId((int) $product->getId());
        $sellerRow = $seller->getData()[0] ?? null;
        if ($sellerRow) {
            $sellerData = $this->marketplaceHelper->getSellerDataBySellerId((int) $sellerRow['seller_id'])->getData()[0] ?? [];
            $shopTitle = (string) ($sellerData['shop_title'] ?? '');
            if ($shopTitle !== '') {
                return $shopTitle;
            }
        }

        return '';
    }
}
