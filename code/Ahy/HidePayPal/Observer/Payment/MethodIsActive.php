<?php

declare(strict_types=1);

namespace Ahy\HidePayPal\Observer\Payment;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Payment\Model\MethodList;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Ahy\ThemeCustomization\Helper\Data;
use Psr\Log\LoggerInterface;

class MethodIsActive implements ObserverInterface
{
    protected $methodList;
    protected $checkoutSession;
    protected $ahyHelper;
    protected $cookieManager;
    protected $logger;
    protected array $blockedCategoryIds = [3614, 921, 490, 521, 44 ]; 
    protected array $blockedSkuPrefixes = ['KIN-',];

    public function __construct(
        MethodList $methodList,
        CheckoutSession $checkoutSession,
        Data $ahyHelper,
        CookieManagerInterface $cookieManager,
        LoggerInterface $logger
    ) {
        $this->methodList = $methodList;
        $this->checkoutSession = $checkoutSession;
        $this->ahyHelper = $ahyHelper;
        $this->cookieManager = $cookieManager;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        /** @var PaymentInterface $payment */
        $payment = $observer->getEvent()->getData('method_instance');
        $quote = $this->checkoutSession->getQuote();

        // Check for country in cookies
        $countryCode = $this->cookieManager->getCookie('country') ?? '';
        if (in_array(needle: $countryCode, haystack: ['FR', 'NL'], strict: true)) {
            // if ($payment->getCode() === 'paypal_express') {
                $result = $observer->getEvent()->getResult();
                $result->setData('is_available', false);
                return;
            // }
        }
        
        $isFflRequired = false;
        $isAgeVerificationRequired = false;

        foreach ($quote->getAllItems() as $item) {
            try {
                $product        = $item->getProduct();
                $productId      = $product->getId();
                $isFflRequired  = $this->ahyHelper->getProductAttribute(productId: $productId, attributeCode: 'ffl_selection_required');
                $isAgeVerificationRequired = $this->ahyHelper->getProductAttribute(productId: $productId, attributeCode: 'age_verification_required');

                if ($isAgeVerificationRequired || $isFflRequired) {
                    break;
                }
            } catch (\Exception $e) {
                $product = $item->getProduct();
                $productName = $product ? $product->getName() : 'Unknown Product';
                $this->logger->error("Error occurred for product: $productName. Exception: " . $e->getMessage());
            }
        }

        if (($isAgeVerificationRequired || $isFflRequired ||  $this->hasBlockedCategoryInQuote()) && $payment->getCode() === 'paypal_express' || $this->hasBlockedSkuInQuote() && $payment->getCode() === 'paypal_express') {
            $result = $observer->getEvent()->getResult();
            $result->setData('is_available', false);

            $quote->getPayment()->setMethod('authnetahypayment');
            $quote->save();
        }
    }

    private function isProductInBlockedCategory(\Magento\Catalog\Model\Product $product): bool
    {
        $categoryCollection = $product->getCategoryCollection()->addAttributeToSelect('path');

        foreach ($categoryCollection as $category) {
            $pathIds = explode('/', $category->getPath());
            if (array_intersect($this->blockedCategoryIds, $pathIds)) {
                return true;
            }
        }

        return false;
    }

    private function hasBlockedSku(\Magento\Catalog\Model\Product $product): bool
    {
        $sku = (string)$product->getSku();

        foreach ($this->blockedSkuPrefixes as $prefix) {
            if (str_starts_with($sku, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function hasBlockedCategoryInQuote(): bool
    {
        $quote = $this->checkoutSession->getQuote();

        foreach ($quote->getAllItems() as $item) {
            $product = $item->getProduct();
            if (!$product || !$product->getId()) {
                continue;
            }

            if ($this->isProductInBlockedCategory($product)) {
                return true;
            }
        }

        return false;
    }
    private function hasBlockedSkuInQuote(): bool
    {
        $quote = $this->checkoutSession->getQuote();

        foreach ($quote->getAllItems() as $item) {
            $product = $item->getProduct();
            if (!$product || !$product->getId()) {
                continue;
            }

            if ($this->hasBlockedSku($product)) {
                return true;
            }
        }

        return false;
    }
}