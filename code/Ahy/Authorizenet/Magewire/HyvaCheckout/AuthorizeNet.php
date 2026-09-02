<?php

namespace Ahy\Authorizenet\Magewire\HyvaCheckout;

use Hyva\Checkout\Model\Magewire\Component\EvaluationInterface;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
use Magewirephp\Magewire\Component;
use Magento\Checkout\Model\Session;
use Ahy\Authorizenet\Service\AuthorizeNetApi;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Exception;
use Magento\Framework\Exception\CouldNotSaveException;
use Ahy\Authorizenet\Helper\Data;

class AuthorizeNet extends Component implements EvaluationInterface
{
    protected $eventManager;
    protected $checkoutSession;
    protected $quoteRepository;
    protected $paymentInfo;
    protected $_authorizeNetApi;
    protected $_grandTotal;
    protected $_helperData;

    public $cardDetails = 'NA';
    public $cardNumber = null;
    public $expireMonth = null;
    public $expireYear = null;
    public $cardCvv = null;
    public $checkboxChecked;

    public function __construct(
        AuthorizeNetApi             $authorizeNetApi,
        Session                     $checkoutSession,
        CartRepositoryInterface     $quoteRepository,
        CartInterface               $cartInterface,
        Data                        $helperData,
        EventManager                $eventManager
    ) {
        $this->_authorizeNetApi     = $authorizeNetApi;
        $this->eventManager         = $eventManager;
        $this->checkoutSession      = $checkoutSession;
        $this->quoteRepository      = $quoteRepository;
        $this->quote                = $cartInterface;
        $this->_helperData          = $helperData;
    }

    public function createCharge()
    {
        // Your code to create a charge

        return $responseJson;
    }

    private function _storeDataInSessionVariable()
    {
        // Perform validation
        $encryptionKey      = $this->_helperData->getEncryptionKey();
        $quoteId            = $this->checkoutSession->getQuoteId();
        $quote              = $this->quoteRepository->get($quoteId);
        $this->_grandTotal  = $quote->getGrandTotal();
        // Store data in the session
        $this->checkoutSession->setData('B4yPd7T5mWuR',         $this->_encrypt($this->cardNumber   ?? 0,   $encryptionKey));
        $this->checkoutSession->setData('V3g6dC4hQ9m8X2L5',     $this->_encrypt($this->expireMonth  ?? 0,   $encryptionKey));
        $this->checkoutSession->setData('L8wKSe1cUVm',          $this->_encrypt($this->expireYear   ?? 0,   $encryptionKey));
        $this->checkoutSession->setData('kzgD3B7n',             $this->_encrypt($this->cardCvv      ?? 0,   $encryptionKey));
        $this->checkoutSession->setData('grandTotal',           $this->_encrypt($this->_grandTotal  ?? 0,   $encryptionKey));
        // if (!empty($this->cardNumber) ) {
        //     // Return true if the data is successfully stored
        //     return true;
        // }
        // // Return false if any of the variables are null or empty
        // return true;
    }

    private function _encrypt($dataString, $key)
    {
        $method             = 'aes-256-cbc';
        $key                = substr(hash('sha256', $key), 0, 32);
        $iv                 = random_bytes(16);
        $encryptedData      = openssl_encrypt($dataString, $method, $key, OPENSSL_RAW_DATA, $iv);
        $encryptedString    = base64_encode($iv . $encryptedData);
        return $encryptedString;
    }

    public function evaluateCompletion(EvaluationResultFactory $resultFactory): EvaluationResultInterface
    {
        // card validation
        
        $this->_storeDataInSessionVariable();

        return $resultFactory->createSuccess();

    }
}
