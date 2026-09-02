<?php

namespace Bitrail\HyvaCheckout\Model;

use \Magento\Framework\Exception\LocalizedException;
use \Magento\Framework\App\Config\ScopeConfigInterface;
use \Magento\Checkout\Model\Session;
use \Magento\Framework\Model\Context;
use \Magento\Framework\Registry;
use \Magento\Framework\Api\ExtensionAttributesFactory;
use \Magento\Framework\Api\AttributeValueFactory;
use \Magento\Payment\Helper\Data;
use \Magento\Payment\Model\Method\Logger;
use Bitrail\HyvaCheckout\Gateway\Http\Client\BitrailClient;
use Bitrail\PaymentGateway\Gateway\Http\Client\BitrailOrderTokenizer;
use \Magento\Payment\Model\Method\AbstractMethod;
use Bitrail\HyvaCheckout\Model\Config\ConfigProvider;

class Bitrail extends AbstractMethod
{
    protected $_code = 'bitrail';

    private const SESSION_KEY_VERIFICATION_TOKEN_PREFIX = 'bitrail_verification_token_';
    private const SESSION_KEY_FC_TRANSACTION_ID_PREFIX = 'bitrail_fc_transaction_id_';

    protected $checkoutSession;
    protected $configProvider;
    protected $bitrailClient;

    public function __construct(
        Session $checkoutSession,
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        Data $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        ConfigProvider $configProvider,
        BitrailClient $bitrailClient
    ) {
        $this->configProvider = $configProvider;
        $this->scopeConfig = $scopeConfig;
        $this->checkoutSession = $checkoutSession;
        $this->bitrailClient = $bitrailClient;

        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
        );
    }

    public function getTitle()
    {
        return __('');
    }



    public function validate()
    {
        parent::validate();

        $quote = $this->checkoutSession->getQuote();

        if ($quote) {
            $orderNumber = $quote->getReservedOrderId();
            $verificationToken = $this->checkoutSession->getData(self::SESSION_KEY_VERIFICATION_TOKEN_PREFIX . $orderNumber)
                ?: $this->checkoutSession->getData($orderNumber);
            $expectedFcTransactionId = $this->checkoutSession->getData(self::SESSION_KEY_FC_TRANSACTION_ID_PREFIX . $orderNumber)
                ?: $this->checkoutSession->getData($orderNumber . '_fc_transaction_id');

            if (!$verificationToken) {
                throw new LocalizedException(__('Unable to verify payment: Invalid state.'));
            }
            if (!$expectedFcTransactionId) {
                throw new LocalizedException(__('Unable to verify payment: Missing fc_transaction_id.'));
            }

            try {
                $verificationResponseData = $this->bitrailClient->verifyTransaction($verificationToken);
                $verifiedFcTransactionId = $verificationResponseData['fc_transaction_id'] ?? null;

                if (!$verifiedFcTransactionId) {
                    throw new LocalizedException(__('Unable to verify payment: Missing fc_transaction_id in verification response.'));
                }

                if ((string) $verifiedFcTransactionId !== (string) $expectedFcTransactionId) {
                    throw new LocalizedException(__('Unable to verify payment: transaction id mismatch.'));
                }

                return $this;

            } catch (\Exception $e) {
                throw new LocalizedException(__('Unable to verify payment: ' . $e->getMessage()));
            }

        } else {
            throw new LocalizedException(__('Unable to verify payment: Quote is no longer available.'));
        }

    }

}
