<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace BitRail\PaymentGateway\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\DataObject;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

use BitRail\PaymentGateway\Gateway\Http\Client\BitrailClient;


class DataAssignObserver extends AbstractDataAssignObserver
{
    /**
     * @var Session
     */
    protected $checkoutSession;
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    public function __construct(Session $checkoutSession, ScopeConfigInterface $scopeConfig)
    {
        $this->checkoutSession = $checkoutSession;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $method = $this->readMethodArgument($observer);
        if (!$method || $method->getCode() !== 'bitrail_gateway') {
            return;
        }

        $data = $this->readDataArgument($observer);
        $token = null;
        $expectedFcTransactionId = null;

        $additional = $data->getDataByKey('additional_data');
        if ($additional instanceof DataObject) {
            $additional = $additional->getData();
        }
        if (is_array($additional)) {
            $token = $additional['orderVerificationToken'] ?? null;
            $expectedFcTransactionId = $additional['fcTransactionId'] ?? null;
        }

        if (!$token) {
            throw new \Exception(__('Missing required payment additional_data: orderVerificationToken'));
        }
        if (!$expectedFcTransactionId) {
            throw new \Exception(__('Missing required payment additional_data: fcTransactionId'));
        }

        $environment = $this->scopeConfig->getValue(
            'payment/bitrail_gateway/environment',
            ScopeInterface::SCOPE_STORE
        );
        $bitrailClient = new BitrailClient($environment);
        $verificationData = $bitrailClient->verifyTransaction($token);
        $verifiedFcTransactionId = null;
        if (is_array($verificationData) && isset($verificationData['fc_transaction_id'])) {
            $verifiedFcTransactionId = $verificationData['fc_transaction_id'];
        }

        if (!$verifiedFcTransactionId) {
            throw new \Exception(__('Unable to verify payment: Missing fc_transaction_id in verification response.'));
        }
        if ((string) $verifiedFcTransactionId !== (string) $expectedFcTransactionId) {
            throw new \Exception(__('Unable to verify payment: transaction id mismatch.'));
        }
    }
}
