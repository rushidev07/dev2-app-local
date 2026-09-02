<?php

namespace Bitrail\HyvaCheckout\Controller\Checkout;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Checkout\Model\Session;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Bitrail\PaymentGateway\Gateway\Http\Client\ClientMock;

class RegisterPayment extends Action
{
  private const SESSION_KEY_VERIFICATION_TOKEN_PREFIX = 'bitrail_verification_token_';
  private const SESSION_KEY_FC_TRANSACTION_ID_PREFIX = 'bitrail_fc_transaction_id_';

  protected $checkoutSession;
  protected $resultJsonFactory;
  private CartRepositoryInterface $quoteRepository;

  public function __construct(
    Context $context,
    Session $checkoutSession,
    JsonFactory $resultJsonFactory,
    CartRepositoryInterface $quoteRepository
  ) {
    parent::__construct($context);

    $this->checkoutSession = $checkoutSession;
    $this->resultJsonFactory = $resultJsonFactory;
    $this->quoteRepository = $quoteRepository;
  }

  public function execute()
  {
    $resultJson = $this->resultJsonFactory->create();
    $reqParams = $this->getRequest()->getParams();

    if (!isset($reqParams['nonceCode']) || $reqParams['nonceCode'] !== ClientMock::getNonceCode()) {
      return $resultJson->setData(['success' => false, 'error' => 'Invalid nonce code.']);
    }

    if (!isset($reqParams['orderId']) || !isset($reqParams['verificationToken']) || !isset($reqParams['fcTransactionId'])) {
      return $resultJson->setData(['success' => false, 'error' => 'Required request parameters not set.']);
    }

    $orderId = $reqParams['orderId'];
    $verificationToken = $reqParams['verificationToken'];
    $fcTransactionId = $reqParams['fcTransactionId'];

    $quote = $this->checkoutSession->getQuote();
    if (!$quote || !$quote->getId()) {
      return $resultJson->setData(['success' => false, 'error' => 'Quote is no longer available.']);
    }

    if ($quote->getReservedOrderId() !== $orderId) {
      return $resultJson->setData(['success' => false, 'error' => 'Order ID does not match the current quote.']);
    }

    $payment = $quote->getPayment();
    if ($payment) {
      // Ensure the current quote uses BitRail payment method when the order is placed.
      $payment->setMethod('bitrail');
      $quote->setPayment($payment);
    }
    $this->quoteRepository->save($quote);

    $this->checkoutSession->setData(self::SESSION_KEY_VERIFICATION_TOKEN_PREFIX . $orderId, $verificationToken);
    $this->checkoutSession->setData(self::SESSION_KEY_FC_TRANSACTION_ID_PREFIX . $orderId, $fcTransactionId);

    return $resultJson->setData([
      'success' => true,
      'data' => [
        'verificationToken' => $this->checkoutSession->getData(self::SESSION_KEY_VERIFICATION_TOKEN_PREFIX . $orderId),
        'fcTransactionId' => $this->checkoutSession->getData(self::SESSION_KEY_FC_TRANSACTION_ID_PREFIX . $orderId),
      ]
    ]);

  }
}
