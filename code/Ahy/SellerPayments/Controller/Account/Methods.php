<?php
namespace Ahy\SellerPayments\Controller\Account;

use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Webkul\Marketplace\Helper\Data as MarketplaceHelper;
use Magento\Customer\Model\Url as CustomerUrl;

class Methods extends \Magento\Framework\App\Action\Action
{
    protected $resultPageFactory;
    protected $marketplaceHelper;
    protected $customerSession;
    protected $customerUrl;

    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        MarketplaceHelper $marketplaceHelper,
        CustomerSession $customerSession,
        CustomerUrl $customerUrl
    ) {
        $this->resultPageFactory = $resultPageFactory;
        $this->marketplaceHelper = $marketplaceHelper;
        $this->customerSession = $customerSession;
        $this->customerUrl = $customerUrl;
        parent::__construct($context);
    }


    public function dispatch(RequestInterface $request)
    {
        $loginUrl = $this->customerUrl->getLoginUrl();

        if (!$this->customerSession->authenticate($loginUrl)) {
            $this->_actionFlag->set('', self::FLAG_NO_DISPATCH, true);
        }

        return parent::dispatch($request);
    }

    public function execute()
    {
        if (!$this->marketplaceHelper->isSeller()) {
            return $this->resultRedirectFactory->create()->setPath(
                '*/*/becomeseller',
                ['_secure' => $this->getRequest()->isSecure()]
            );
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->addHandle('sellerpayments_layout2_account_methods');
 
        $resultPage->getConfig()->getTitle()->set(__('Manage Seller Payment Methods'));

        return $resultPage;
    }
}

