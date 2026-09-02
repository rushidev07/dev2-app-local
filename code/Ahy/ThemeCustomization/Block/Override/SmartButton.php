<?php

namespace Ahy\ThemeCustomization\Block\Override;

use Magento\Paypal\Block\Express\InContext\Minicart\SmartButton as OriginalSmartButton;

class SmartButton extends OriginalSmartButton
{

    protected $session;
    protected $serializer; // Define the serializer property
    protected $urlBuilder; // Define the urlBuilder property
    protected $smartButtonConfig; // Define the urlBuilder property
    protected array $blockedCategoryIds = [3614, 921, 490, 521, 44];
    protected array $blockedSkuPrefixes = ['KIN-',];

    /**
     * Constructor
     *
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\Paypal\Model\ConfigFactory $configFactory
     * @param \Magento\Checkout\Model\Session $session
     * @param \Magento\Payment\Model\MethodInterface $payment
     * @param \Magento\Framework\Serialize\SerializerInterface $serializer
     * @param \Magento\Paypal\Model\SmartButtonConfig $smartButtonConfig
     * @param \Magento\Framework\UrlInterface $urlBuilder
     * @param \Magento\Quote\Model\QuoteIdToMaskedQuoteId $quoteIdToMaskedQuoteId
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Paypal\Model\ConfigFactory $configFactory,
        \Magento\Checkout\Model\Session $session,
        \Magento\Payment\Model\MethodInterface $payment,
        \Magento\Framework\Serialize\SerializerInterface $serializer,
        \Magento\Paypal\Model\SmartButtonConfig $smartButtonConfig,
        \Magento\Framework\UrlInterface $urlBuilder,
        \Magento\Quote\Model\QuoteIdToMaskedQuoteId $quoteIdToMaskedQuoteId,
        array $data = []
    ) {
        parent::__construct($context, $configFactory, $session, $payment, $serializer, $smartButtonConfig, $urlBuilder, $quoteIdToMaskedQuoteId, $data);
        $this->serializer = $serializer;
        $this->urlBuilder = $urlBuilder;
        $this->smartButtonConfig = $smartButtonConfig;
        $this->session = $session;
    }
    /**
     * Returns string to initialize js component
     *
     * @return string
     */
    public function getJsInitParams(): string
    {
        if ($this->hasBlockedCategoryInQuote() || $this->hasBlockedSkuInQuote()) {
            return $this->serializer->serialize([
                'Magento_Paypal/js/in-context/button' => [
                    'clientConfig' => []
                ]
            ]);
        }

        $config = ['Magento_Paypal/js/in-context/button' => []];
        if ($this->getQuoteId() === null) {
            $quoteId = "";
        } else {
            $quoteId = $this->getQuoteId();
        }
        // if (!empty($quoteId)) {
        $clientConfig = [
            'quoteId' => $quoteId,
            'customerId' => $this->session->getQuote()->getCustomerId(),
            'button' => 1,
            'getTokenUrl' => $this->urlBuilder->getUrl(
                'paypal/express/getTokenData',
                ['_secure' => $this->getRequest()->isSecure()]
            ),
            'onAuthorizeUrl' => $this->urlBuilder->getUrl(
                'paypal/express/onAuthorization',
                ['_secure' => $this->getRequest()->isSecure()]
            ),
            'onCancelUrl' => $this->urlBuilder->getUrl(
                'paypal/express/cancel',
                ['_secure' => $this->getRequest()->isSecure()]
            )
        ];
        $smartButtonsConfig = $this->getIsShoppingCart()
            ? $this->smartButtonConfig->getConfig('cart')
            : $this->smartButtonConfig->getConfig('mini_cart');
        $clientConfig = array_replace_recursive($clientConfig, $smartButtonsConfig);
        $config = [
            'Magento_Paypal/js/in-context/button' => [
                'clientConfig' => $clientConfig
            ]
        ];
        // }
        $json = $this->serializer->serialize($config);
        return $json;
    }

    private function hasBlockedCategoryInQuote(): bool
    {
        $quote = $this->session->getQuote();
        foreach ($quote->getAllItems() as $item) {
            $product = $item->getProduct();
            if (!$product || !$product->getId()) {
                continue;
            }
            $categoryCollection = $product->getCategoryCollection()->addAttributeToSelect('path');
            foreach ($categoryCollection as $category) {
                $pathIds = explode('/', $category->getPath());
                if (array_intersect($this->blockedCategoryIds, $pathIds)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function hasBlockedSkuInQuote(): bool
    {
        $quote = $this->session->getQuote();

        foreach ($quote->getAllItems() as $item) {

            $sku = (string)$item->getSku();

            foreach ($this->blockedSkuPrefixes as $prefix) {
                if (str_starts_with($sku, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
