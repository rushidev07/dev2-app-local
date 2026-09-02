<?php
namespace Ahy\SellerPayments\Controller\Account;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\Action;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Ahy\SellerPayments\Model\SellerPaymentFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Ahy\SellerPayments\Model\MarketplaceUserDataFactory;

class Delete extends Action
{
    protected $resultRedirectFactory;
    protected $messageManager;
    protected $sellerPaymentFactory;
    protected $customerSession;
    protected $marketplaceUserDataFactory;


    public function __construct(
        Context $context,
        RedirectFactory $resultRedirectFactory,
        ManagerInterface $messageManager,
        SellerPaymentFactory $sellerPaymentFactory,
        CustomerSession $customerSession,
        MarketplaceUserDataFactory $marketplaceUserDataFactory
    ) {
        parent::__construct($context);
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->messageManager = $messageManager;
        $this->sellerPaymentFactory = $sellerPaymentFactory;
        $this->customerSession = $customerSession;
        $this->marketplaceUserDataFactory = $marketplaceUserDataFactory;
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $id = $this->getRequest()->getParam('id');
        $customerId = $this->customerSession->getCustomerId();

        if (!$id) {
            $this->messageManager->addErrorMessage(__('Payment method ID missing.'));
            return $resultRedirect->setPath('sellerpayments/account/methods');
        }

        try {
            $payment = $this->sellerPaymentFactory->create()->load($id);

            if (!$payment->getId() || $payment->getSellerId() != $customerId) {
                $this->messageManager->addErrorMessage(__('Payment method not found or you do not have permission.'));
                return $resultRedirect->setPath('sellerpayments/account/methods');
            }

            $payment->delete();

            try {
                $marketplaceUserData = $this->marketplaceUserDataFactory->create();
                $marketplaceUserData->load($customerId, 'seller_id');

                if ($marketplaceUserData->getId()) {
                    $existingPaymentSources = $marketplaceUserData->getPaymentSource();
                    $paymentSourcesArray = [];

                    if ($existingPaymentSources) {
                        $paymentSourcesArray = json_decode($existingPaymentSources, true);
                        if (!is_array($paymentSourcesArray)) {
                            $paymentSourcesArray = [];
                        }
                    }

                    foreach ($paymentSourcesArray as $key => $paymentMethod) {
                        if ($paymentMethod['Bank Name'] === $payment->getBankName()
                            && $paymentMethod['Account Number'] === $payment->getAccountNumber()
                            && $paymentMethod['Routing Number'] === $payment->getRoutingNumber()) {
                            unset($paymentSourcesArray[$key]);
                            break;
                        }
                    }
                    $paymentSourcesArray = array_values($paymentSourcesArray);
                    $jsonPaymentSources = json_encode($paymentSourcesArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $marketplaceUserData->setPaymentSource(trim($jsonPaymentSources));
                    $marketplaceUserData->save();
                    $this->messageManager->addSuccessMessage(__('Payment method deleted successfully.'));

                }
            } catch (\Exception $e) {
                $this->logger->error('Error updating marketplace user data after payment deletion: ' . $e->getMessage());
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Error deleting payment method.'));
        }

        return $resultRedirect->setPath('sellerpayments/account/methods');
    }
}
