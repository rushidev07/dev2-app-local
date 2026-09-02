<?php
namespace Ahy\SellerPayments\Block;

use Magento\Framework\View\Element\Html\Link\Current;
use Webkul\Marketplace\Helper\Data as MarketplaceHelper;
use Magento\Framework\App\DefaultPathInterface;
use Ahy\SellerPayments\Model\SellerPaymentFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Ahy\SellerPayments\Helper\PaymentData;

class Link extends Current
{
    protected $marketplaceHelper;
    protected $sellerPaymentFactory;
    protected $customerSession;

   public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        DefaultPathInterface $defaultPath,
        MarketplaceHelper $marketplaceHelper,
        CustomerSession $customerSession,
        SellerPaymentFactory $sellerPaymentFactory,
        PaymentData $paymentDataHelper, 
        array $data = []
    )
    {
        $this->marketplaceHelper = $marketplaceHelper;
        $this->customerSession = $customerSession;
        $this->sellerPaymentFactory = $sellerPaymentFactory;
        $this->paymentDataHelper = $paymentDataHelper;
        parent::__construct($context, $defaultPath, $data);
    }

    protected function _toHtml()
    {
        if (!$this->marketplaceHelper->isSeller()) {
            return '';
        }
        return parent::_toHtml();
    }

    public function getCurrentUrl()
    {
        return $this->_urlBuilder->getCurrentUrl();
    }

    /**
     * Fetch saved seller payment data
     *
     * @return \Ahy\SellerPayments\Model\SellerPayment|null
     */
    public function getSellerPaymentData()
    {
        $customerId = $this->customerSession->getCustomerId();
        if (!$customerId) {
            return null;
        }

        $payment = $this->sellerPaymentFactory->create();
        return $payment->load($customerId, 'seller_id');
    }

    public function getSellerPayments()
    {
        $customerId = $this->customerSession->getCustomerId();
        if (!$customerId) {
            return [];
        }

        $collection = $this->sellerPaymentFactory->create()->getCollection()
            ->addFieldToFilter('seller_id', $customerId);

        return $collection->getItems();
    }


}
