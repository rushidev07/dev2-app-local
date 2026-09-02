<?php
namespace Ahy\ThemeCustomization\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\Action\Action;
use Magento\Catalog\Model\ProductRepository;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\ResourceConnection;

class RedirectToSeller implements ObserverInterface
{
    protected $redirect;
    protected $actionFlag;
    protected $response;
    protected $logger;
    protected $resource;
    protected $productRepository;
    protected $configurableType;

    // List of sellers to redirect
    protected $redirectSellers = [
        'Jyoti Foods',
        'Haymaker Coffee',
        'Dirty Duck',
        'Old Army Coffee',
        'Breakthrough Clean',
        'Summit Coffee',
        'ReadyWise',
        'Ales Grey',
        'Dfndr Armor',
        'Dixie Jet Lures',
        'Elite Survival Systems',
        'Extreme Mist',
        'Innovative Outdoors',
        'HandleIt Grips',
        'IR Tools',
        'Karmik Outdoors',
        'Kysek Coolers',
        'Lilly Brush',
        'Modern Spartan Systems',
        'Norvine',
        'Sherry Steele',
        'Spirit Arms',
        'SSP Eyewear',
        'Starla\'s Seasoning',
        'Survival Filter',
        'Twisted Goat',


    ];

    public function __construct(
        RedirectInterface $redirect,
        ActionFlag $actionFlag,
        ResponseInterface $response,
        LoggerInterface $logger,
        ResourceConnection $resource,
        ProductRepository $productRepository,
        ConfigurableType $configurableType
    ) {
        $this->redirect = $redirect;
        $this->actionFlag = $actionFlag;
        $this->response = $response;
        $this->logger = $logger;
        $this->resource = $resource;
        $this->productRepository = $productRepository;
        $this->configurableType = $configurableType;
    }

    public function execute(Observer $observer)
    {
        try {
            /** @var Action $controller */
            $controller = $observer->getEvent()->getControllerAction();
            $productId = $controller->getRequest()->getParam('id');

            if (!$productId) {
                return;
            }

            $connection = $this->resource->getConnection();
            $marketplaceProductTable = $this->resource->getTableName('marketplace_product');
            $marketplaceUserTable = $this->resource->getTableName('marketplace_userdata');
            $product = $this->productRepository->getById($productId);
            $childProductIds = [];

            if ($product->getTypeId() === 'configurable') {
                $childProductIds = $this->configurableType->getChildrenIds($productId)[0] ?? [];
            } else {
                $childProductIds[] = $productId;
            }

            $uniqueSellers = [];

            foreach ($childProductIds as $pid) {
                $mpProductDataList = $connection->fetchAll(
                    "SELECT seller_id FROM {$marketplaceProductTable} WHERE mageproduct_id = :product_id",
                    ['product_id' => $pid]
                );

                foreach ($mpProductDataList as $mpProductData) {
                    if (!empty($mpProductData['seller_id'])) {
                        $uniqueSellers[$mpProductData['seller_id']] = true;
                    }
                }
            }

            if (empty($uniqueSellers)) {
                return;
            }

            $handledShopUrls = [];

            foreach (array_keys($uniqueSellers) as $sellerId) {
                $sellerName = $connection->fetchOne(
                    "SELECT shop_title FROM {$marketplaceUserTable} WHERE seller_id = :seller_id",
                    ['seller_id' => $sellerId]
                );

                if (!$this->isRedirectSeller($sellerName)) {
                    continue;
                }

                $shopUrl = $connection->fetchOne(
                    "SELECT shop_url FROM {$marketplaceUserTable} WHERE seller_id = :seller_id",
                    ['seller_id' => $sellerId]
                );

                if ($shopUrl && !isset($handledShopUrls[$shopUrl])) {
                    $this->logger->info("RedirectToSeller: Redirecting product ID $productId to seller '$sellerName', URL: $shopUrl");
                    $handledShopUrls[$shopUrl] = true;
                    $this->actionFlag->set('', Action::FLAG_NO_DISPATCH, true);
                    $this->response->setRedirect($shopUrl);
                    return;
                }
            }

        } catch (\Exception $e) {
            $this->logger->error("RedirectToSeller Exception: " . $e->getMessage());
        }
    }

    private function isRedirectSeller(string $sellerName): bool
    {
        $normalizedSeller = strtolower(trim($sellerName));

        foreach ($this->redirectSellers as $allowedSeller) {
            $normalizedAllowed = strtolower(trim($allowedSeller));
            if (
                strpos($normalizedSeller, $normalizedAllowed) !== false ||
                strpos($normalizedAllowed, $normalizedSeller) !== false
            ) {
                return true;
            }
        }

        return false;
    }

}
