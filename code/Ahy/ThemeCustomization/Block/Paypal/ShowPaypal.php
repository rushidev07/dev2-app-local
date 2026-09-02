<?php

namespace Ahy\ThemeCustomization\Block\Paypal;
use Magento\Checkout\Model\Session as CheckoutSession;
use Ahy\ThemeCustomization\Helper\Data;
use Psr\Log\LoggerInterface;

class ShowPaypal extends \Magento\Framework\View\Element\Template
{
    protected $smartButton;
    protected $checkoutSession;
    protected $ahyHelper;
    protected $logger;
    protected array $blockedCategoryIds = [3614, 921, 490, 521, 44];
    protected array $blockedSkuPrefixes = ['KIN-',];

    public function __construct(
        \Magento\Paypal\Block\Express\InContext\Minicart\SmartButton $smartButton,
        \Magento\Framework\View\Element\Template\Context $context,
        CheckoutSession $checkoutSession,
        Data $ahyHelper,
        LoggerInterface $logger,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->checkoutSession = $checkoutSession;
        $this->ahyHelper = $ahyHelper;
        $this->smartButton = $smartButton;
        $this->logger = $logger;
    }

    public function getWidgetJson()
    {
        $checkForTheRestrictedProduct = $this->checkForTheRestrictedItemsInCart();
        if($checkForTheRestrictedProduct){
            $widgetData = json_decode($this->smartButton->getJsInitParams(), true);
            if (isset($widgetData['Magento_Paypal/js/in-context/button'])) {
                return json_encode($widgetData['Magento_Paypal/js/in-context/button']);
            }
        }
        return '';
    }

    public function checkForTheRestrictedItemsInCart(){
        $quote = $this->checkoutSession->getQuote();
        $isFflRequired = false;
        $isAgeVerificationRequired = false;
        $isBlockedCategory = false;
        foreach ($quote->getAllItems() as $item) {
            try {
                $product        = $item->getProduct();
                $productId      = $product->getId();
                $isFflRequired  = $this->ahyHelper->getProductAttribute($productId, 'ffl_selection_required');
                $isAgeVerificationRequired = $this->ahyHelper->getProductAttribute($productId, 'age_verification_required');
                $isBlockedCategory = $this->isProductInBlockedCategory($product);
                if ($isFflRequired || $isAgeVerificationRequired || $isBlockedCategory) {
                    break;
                }
                $isBlockedSku = $this->hasBlockedSku($product);
                if ( $isFflRequired ||$isAgeVerificationRequired || $isBlockedSku) {
                    break;
                }   
                
               
            } catch (\Exception $e) {
                $product = $item->getProduct();
                $productName = $product ? $product->getName() : 'Unknown Product';
                $this->logger->error("Error occurred for product: $productName. Exception: " . $e->getMessage());
            }
        }
        if (($isAgeVerificationRequired || $isFflRequired || $isBlockedCategory)){
            return false;
        }
        return true;
    }

    private function isProductInBlockedCategory(\Magento\Catalog\Model\Product $product): bool
    {
        $blockedCategoryIds = $this->blockedCategoryIds;
        $categoryCollection = $product->getCategoryCollection()
            ->addAttributeToSelect('path');
        foreach ($categoryCollection as $category) {
            $pathIds = explode('/', $category->getPath()); 
            if (array_intersect($blockedCategoryIds, $pathIds)) {
                return true; 
            }
        }
        return false; 
    }
    private function hasBlockedSku(\Magento\Catalog\Model\Product $product): bool
    {
        $blockedSkuPrefixes = $this->blockedSkuPrefixes;
        $sku = (string)$product->getSku();
        foreach ($blockedSkuPrefixes as $prefix) {
            if (str_starts_with($sku, $prefix)) {
                return true;
            }
        }
        return false;
    }

     

}
