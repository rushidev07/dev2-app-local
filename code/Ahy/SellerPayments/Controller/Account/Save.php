<?php
namespace Ahy\SellerPayments\Controller\Account;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\Action;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Ahy\SellerPayments\Model\SellerPayment;
use Psr\Log\LoggerInterface;
use Ahy\SellerPayments\Model\MarketplaceUserDataFactory;

class Save extends Action
{
    protected $resultRedirectFactory;
    protected $messageManager;
    protected $customerSession;
    protected $sellerPayment;
    protected $logger;
    protected $marketplaceUserDataFactory;

    public function __construct(
        Context $context,
        RedirectFactory $resultRedirectFactory,
        ManagerInterface $messageManager,
        SellerPayment $sellerPayment,
        CustomerSession $customerSession,
        LoggerInterface $logger,
        MarketplaceUserDataFactory $marketplaceUserDataFactory

    )
    {
        parent::__construct($context);
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->messageManager = $messageManager;
        $this->customerSession = $customerSession;
        $this->sellerPayment = $sellerPayment;
        $this->logger = $logger;
        $this->marketplaceUserDataFactory = $marketplaceUserDataFactory;

    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();

        if (!$this->customerSession->isLoggedIn()) {
            $this->messageManager->addErrorMessage(__('You must be logged in to save payment info.'));
            return $resultRedirect->setPath('customer/account/login');
        }

        if (!$this->getRequest()->isPost()) {
            $this->messageManager->addErrorMessage(__('Invalid request method.'));
            return $resultRedirect->setPath('sellerpayments/account/methods');
        }

        $postData = $this->getRequest()->getPostValue();

        if (empty($postData['bank_name']) || empty($postData['account_number']) || empty($postData['routing_number'])) {
            $this->messageManager->addErrorMessage(__('Please fill in all required fields.'));
            return $resultRedirect->setPath('sellerpayments/account/methods');
        }

        if (!preg_match('/^\d+$/', $postData['account_number'])) {
            $this->messageManager->addErrorMessage(__('Account number must contain only digits.'));
            return $resultRedirect->setPath('sellerpayments/account/methods');
        }

        if (!preg_match('/^\d{9}$/', $postData['routing_number'])) {
            $this->messageManager->addErrorMessage(__('Routing number must be exactly 9 digits.'));
            return $resultRedirect->setPath('sellerpayments/account/methods');
        }

        try {
            $sellerId = $this->customerSession->getCustomerId();
            $id = $this->getRequest()->getParam('id'); // get payment method ID from form (if editing)

            $success = $this->sellerPayment->saveSellerPayment(
                $sellerId,
                $postData['bank_name'],
                $postData['account_number'],
                $postData['routing_number'],
                $id
            );

            if ($success) {
                try {
                    $marketplaceUserData = $this->marketplaceUserDataFactory->create();
                    $marketplaceUserData->load($sellerId, 'seller_id');
                    if (!$marketplaceUserData->getId()) {
                        $this->messageManager->addErrorMessage(__('Seller not found in marketplace data.'));
                        return $resultRedirect->setPath('sellerpayments/account/methods');
                    }
                    $existingPaymentSources = $marketplaceUserData->getPaymentSource();
                    $paymentSourcesArray = [];
                    if ($existingPaymentSources) {
                        $paymentSourcesArray = json_decode($existingPaymentSources, true);
                        if (!is_array($paymentSourcesArray)) {
                            $paymentSourcesArray = [];
                        }
                    }
                    $id = $this->getRequest()->getParam('id'); 
                    $newPayment = [
                        'Bank Name' => $postData['bank_name'],
                        'Account Number' => $postData['account_number'],
                        'Routing Number' => $postData['routing_number'],
                    ];
                    if ($id) {
                        $found = false;
                        foreach ($paymentSourcesArray as &$payment) {
                            if (isset($payment['id']) && $payment['id'] == $id) {
                                $payment = array_merge($payment, $newPayment);
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            $newPayment['id'] = $id;
                            $paymentSourcesArray[] = $newPayment;
                        }
                    } else {
                        $newPayment['id'] = uniqid();
                        $paymentSourcesArray[] = $newPayment;
                    }

                    $jsonPaymentSources = json_encode($paymentSourcesArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $marketplaceUserData->setPaymentSource(trim($jsonPaymentSources));
                    $marketplaceUserData->save();
                    $this->messageManager->addSuccessMessage(__('Payment information saved successfully.'));
                } catch (\Exception $e) {
                    $this->logger->error('Error loading marketplace user data: ' . $e->getMessage());
                    $this->messageManager->addErrorMessage(__('An error occurred while loading marketplace user data.'));
                    return $resultRedirect->setPath('sellerpayments/account/methods');
                }
            } else {
                $this->messageManager->addErrorMessage(__('Failed to save payment information.'));
            }

            return $resultRedirect->setPath('sellerpayments/account/methods');
        }
        catch (\Exception $e) {
            $this->logger->error('SellerPayments Save Error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('An error occurred while saving payment information.'));
            return $resultRedirect->setPath('sellerpayments/account/methods');
        }
    }
}
