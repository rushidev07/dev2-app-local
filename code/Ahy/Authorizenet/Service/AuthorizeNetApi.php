<?php
namespace Ahy\Authorizenet\Service;

use GuzzleHttp\Client;
use GuzzleHttp\ClientFactory;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ResponseFactory;
use Psr\Log\LoggerInterface as Logger;
use GuzzleHttp\Exception\GuzzleException;
use Magento\Framework\Webapi\Rest\Request;
use \Magento\Framework\Filesystem\DirectoryList;
use \Magento\Framework\Serialize\Serializer\Json;
use Ahy\Authorizenet\Logger\Logger as ApiLogger;
use Ahy\Authorizenet\Helper\Data;

class AuthorizeNetApi {
    

    /* The base url for the API. */
    const API_REQUEST_URI_SANDBOX                                   = 'https://apitest.authorize.net/xml/v1/request.api';

    const API_REQUEST_URI_LIVE                                      = 'https://api.authorize.net/xml/v1/request.api';
    
    /* A constant that is used to call the API endpoint. */
    const API_TRANSACTION_KEY                                       = '7yjyy663UaQ59X5T';

    /* API request endpoint*/
    const API_LOGIN_KEY                                             = '7RgW2Dj4QmG';

    public $isSandbox = true;    

    /**
     * @var logger
     */
    private $logger; 
    /**
     * @var Data
     */
    private $_helper;

    /**
     * variable that is used to get the directory list. 
     *
     * @var DirectoryList
     */
    protected $_dir;

    /**
     * @var ResponseFactory
     * 
     * A variable that is used to create the response object. 
     */
    private $_responseFactory;
    
    /**
     * A variable that is used to create the client object. 
     *
     * @var ClientFactory
     */
    private $_clientFactory;

    /**
     * Used to serialize the data
     *
     * @var Json
     */
    private $_json;

    /**
     * @var ApiLogger
     */
    private $_ApiLogger;

    /**
     * This function is the constructor for the class. It takes in a bunch of parameters and assigns them to class variables
     * 
     * @param ClientFactory clientFactory is the factory class that will be used to create the client object.
     * @param ResponseFactory responseFactory is the factory class that will be used to create the response object.
     * @param DirectoryList dir is the Magento directory list object.
     * @param Json json is the Magento Json class.
     * @param Logger logger is the Magento logger.
     * @param ApiLogger ApiLogger is the class that will be used to log the API calls.
     */
    public function __construct(
        ClientFactory           $clientFactory,
        ResponseFactory         $responseFactory,
        DirectoryList           $dir,
        Json                    $json,
        Logger                  $logger,
        Data                    $helper,
        ApiLogger               $apiLogger
    ) {
        $this->_clientFactory   = $clientFactory;
        $this->_responseFactory = $responseFactory;
        $this->_dir             = $dir;
        $this->_json            = $json;
        $this->_helper          = $helper;
        $this->logger           = $logger;
        $this->_ApiLogger       = $apiLogger;
        
    }
    
    public function createCharge($cardNumber, $expireMonth, $expireYear, $cardCvv, $amount, $shippingAddressArray, $billingAddressArray, $customerDetailsArray)
    {
        // return [$cardNumber, $expireMonth, $expireYear, $cardCvv, $amount];
        $apiCredentials = $this->getApiCredentials();
        $expirationDate = $expireYear . '-' . $expireMonth;
        // shipping details
        $shippingCity           = !empty($shippingAddressArray) && isset($shippingAddressArray['city']) ? $shippingAddressArray['city'] : 'Not Available';
        $shippingStreet         = !empty($shippingAddressArray) && isset($shippingAddressArray['street']) ? $shippingAddressArray['street'] : 'Not Available';
        $shippingRegion         = !empty($shippingAddressArray) && isset($shippingAddressArray['region']) ? $shippingAddressArray['region'] : 'Not Available';
        $shippingLastName       = !empty($shippingAddressArray) && isset($shippingAddressArray['lastName']) ? $shippingAddressArray['lastName'] : 'Not Available';
        $shippingPostCode       = !empty($shippingAddressArray) && isset($shippingAddressArray['postcode']) ? $shippingAddressArray['postcode'] : 'Not Available';
        $shippingFirstName      = !empty($shippingAddressArray) && isset($shippingAddressArray['firstName']) ? $shippingAddressArray['firstName'] : 'Not Available';
        $shippingCountryCode    = !empty($shippingAddressArray) && isset($shippingAddressArray['country_code']) ? $shippingAddressArray['country_code'] : 'Not Available';
        // billing details
        $billingCity            = !empty($billingAddressArray) && isset($billingAddressArray['city']) ? $billingAddressArray['city'] : 'Not Available';
        $billingRegion          = !empty($billingAddressArray) && isset($billingAddressArray['region']) ? $billingAddressArray['region'] : 'Not Available';
        $billingStreet          = !empty($billingAddressArray) && isset($billingAddressArray['street']) ? $billingAddressArray['street'] : 'Not Available';
        $billingLastName        = !empty($billingAddressArray) && isset($billingAddressArray['lastName']) ? $billingAddressArray['lastName'] : 'Not Available';
        $billingFirstName       = !empty($billingAddressArray) && isset($billingAddressArray['firstName']) ? $billingAddressArray['firstName'] : 'Not Available';
        $billingPostCode        = !empty($billingAddressArray) && isset($billingAddressArray['postcode']) ? $billingAddressArray['postcode'] : 'Not Available';
        $billingTelephone       = !empty($billingAddressArray) && isset($billingAddressArray['telephone']) ? $billingAddressArray['telephone'] : 'Not Available';
        $billingCountryCode     = !empty($billingAddressArray) && isset($billingAddressArray['country_code']) ? $billingAddressArray['country_code'] : 'Not Available';
        // customer details
        $customerId             = !empty($customerDetailsArray) && isset($customerDetailsArray['customerId']) ? $customerDetailsArray['customerId'] : 'Not Available';
        $customerEmail          = !empty($customerDetailsArray) && isset($customerDetailsArray['email']) ? $customerDetailsArray['email'] : 'Not Available';

        $body = '
            {
                "createTransactionRequest": {
                    "merchantAuthentication": {
                        "name": "' . $apiCredentials['apiKey'] . '",
                        "transactionKey": "'. $apiCredentials['transactionKey'].'"
                    },
                    "transactionRequest": {
                        "transactionType": "authCaptureTransaction",
                        "amount": "' . ($amount !== null ? number_format($amount, 2, '.', '') : '0.00') . '",
                        "payment": {
                            "creditCard": {
                                "cardNumber": "' . $cardNumber . '",
                                "expirationDate": "' . $expirationDate . '",
                                "cardCode": "' . $cardCvv . '"
                            }
                        },
                        "customer":{
                            "type": "individual",
                            "id": "'. $customerId .'",
                            "email": "'. $customerEmail .'"
                        },
                        "billTo": {
                            "firstName": "'. $billingFirstName .'",
                            "lastName": "'. $billingLastName .'",
                            "address": "'. $billingStreet .'",
                            "city": "'. $billingCity .'",
                            "state": "'. $billingRegion .'",
                            "zip": "'. $billingPostCode .'",
                            "country": "'. $billingCountryCode .'",
                            "phoneNumber": "'. $billingTelephone .'"
                            
                        },
                        "shipTo": {
                            "firstName": "'. $shippingFirstName .'",
                            "lastName": "'. $shippingLastName .'",
                            "address": "'. $shippingStreet .'",
                            "city": "'. $shippingCity .'",
                            "state": "'. $shippingRegion .'",
                            "zip": "'. $shippingPostCode .'",
                            "country": "'. $shippingCountryCode .'"
                        }
                    }
                }
            }';
            $this->_ApiLogger->info($body);
        $params             = [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body'          => $body
        ];
        $response           = $this->_doRequest($chargeApiEndpoint = '', Request::HTTP_METHOD_POST, $params);
        $status             = $response->getStatusCode(); // 200 status code
        $responseBody       = $response->getBody();
        $responseContent    = $responseBody->getContents(); // here you will have the API response in JSON format
        $responseContent    = $this->removeByteOrderMarkFromJsonString($responseContent);
        
        return $responseContent;
    }

    public function authorizeCard($cardNumber, $expireMonth, $expireYear, $cardCvv, $amount, $shippingAddressArray, $billingAddressArray, $customerDetailsArray){
        // return [$cardNumber, $expireMonth, $expireYear, $cardCvv, $amount];
        $apiCredentials = $this->getApiCredentials();
        $expirationDate = $expireYear . '-' . $expireMonth;
        // shipping details
        $shippingCity           = !empty($shippingAddressArray) && isset($shippingAddressArray['city']) ? $shippingAddressArray['city'] : 'Not Available';
        $shippingStreet         = !empty($shippingAddressArray) && isset($shippingAddressArray['street']) ? $shippingAddressArray['street'] : 'Not Available';
        $shippingRegion         = !empty($shippingAddressArray) && isset($shippingAddressArray['region']) ? $shippingAddressArray['region'] : 'Not Available';
        $shippingLastName       = !empty($shippingAddressArray) && isset($shippingAddressArray['lastName']) ? $shippingAddressArray['lastName'] : 'Not Available';
        $shippingPostCode       = !empty($shippingAddressArray) && isset($shippingAddressArray['postcode']) ? $shippingAddressArray['postcode'] : 'Not Available';
        $shippingFirstName      = !empty($shippingAddressArray) && isset($shippingAddressArray['firstName']) ? $shippingAddressArray['firstName'] : 'Not Available';
        $shippingCountryCode    = !empty($shippingAddressArray) && isset($shippingAddressArray['country_code']) ? $shippingAddressArray['country_code'] : 'Not Available';
        // billing details
        $billingCity            = !empty($billingAddressArray) && isset($billingAddressArray['city']) ? $billingAddressArray['city'] : 'Not Available';
        $billingRegion          = !empty($billingAddressArray) && isset($billingAddressArray['region']) ? $billingAddressArray['region'] : 'Not Available';
        $billingStreet          = !empty($billingAddressArray) && isset($billingAddressArray['street']) ? $billingAddressArray['street'] : 'Not Available';
        $billingLastName        = !empty($billingAddressArray) && isset($billingAddressArray['lastName']) ? $billingAddressArray['lastName'] : 'Not Available';
        $billingFirstName       = !empty($billingAddressArray) && isset($billingAddressArray['firstName']) ? $billingAddressArray['firstName'] : 'Not Available';
        $billingPostCode        = !empty($billingAddressArray) && isset($billingAddressArray['postcode']) ? $billingAddressArray['postcode'] : 'Not Available';
        $billingTelephone       = !empty($billingAddressArray) && isset($billingAddressArray['telephone']) ? $billingAddressArray['telephone'] : 'Not Available';
        $billingCountryCode     = !empty($billingAddressArray) && isset($billingAddressArray['country_code']) ? $billingAddressArray['country_code'] : 'Not Available';
        // customer details
        $customerId             = !empty($customerDetailsArray) && isset($customerDetailsArray['customerId']) ? $customerDetailsArray['customerId'] : 'Not Available';
        $customerEmail          = !empty($customerDetailsArray) && isset($customerDetailsArray['email']) ? $customerDetailsArray['email'] : 'Not Available';

        $body = '
            {
                "createTransactionRequest": {
                    "merchantAuthentication": {
                        "name": "' . $apiCredentials['apiKey'] . '",
                        "transactionKey": "'. $apiCredentials['transactionKey'].'"
                    },
                    "transactionRequest": {
                        "transactionType": "authOnlyTransaction",
                        "amount": "' . ($amount !== null ? number_format($amount, 2, '.', '') : '0.00') . '",
                        "payment": {
                            "creditCard": {
                                "cardNumber": "' . $cardNumber . '",
                                "expirationDate": "' . $expirationDate . '",
                                "cardCode": "' . $cardCvv . '"
                            }
                        },
                        "customer":{
                            "type": "individual",
                            "id": "'. $customerId .'",
                            "email": "'. $customerEmail .'"
                        },
                        "billTo": {
                            "firstName": "'. $billingFirstName .'",
                            "lastName": "'. $billingLastName .'",
                            "address": "'. $billingStreet .'",
                            "city": "'. $billingCity .'",
                            "state": "'. $billingRegion .'",
                            "zip": "'. $billingPostCode .'",
                            "country": "'. $billingCountryCode .'",
                            "phoneNumber": "'. $billingTelephone .'"
                            
                        },
                        "shipTo": {
                            "firstName": "'. $shippingFirstName .'",
                            "lastName": "'. $shippingLastName .'",
                            "address": "'. $shippingStreet .'",
                            "city": "'. $shippingCity .'",
                            "state": "'. $shippingRegion .'",
                            "zip": "'. $shippingPostCode .'",
                            "country": "'. $shippingCountryCode .'"
                        }
                    }
                }
            }';
        $this->_ApiLogger->info($body);
        $params             = [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body'          => $body
        ];
        $response           = $this->_doRequest($chargeApiEndpoint = '', Request::HTTP_METHOD_POST, $params);
        $status             = $response->getStatusCode(); // 200 status code
        $responseBody       = $response->getBody();
        $responseContent    = $responseBody->getContents(); // here you will have the API response in JSON format
        $responseContent    = $this->removeByteOrderMarkFromJsonString($responseContent);
        
        return $responseContent;
    }

    public function testAuthorizeAPI($apiLoginId, $transactionKey)
    {   
        $body = '
            {
                "authenticateTestRequest": {
                    "merchantAuthentication": {
                        "name": "' . $apiLoginId . '",
                        "transactionKey": "' . $transactionKey . '"
                    }
                }
            }';

        $params             = [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body'          => $body
        ];
        $response           = $this->_doRequest($chargeApiEndpoint = '', Request::HTTP_METHOD_POST, $params);
        $status             = $response->getStatusCode(); // 200 status code
        $responseBody       = $response->getBody();
        $responseContent    = $responseBody->getContents(); // here you will have the API response in JSON format
        
        $responseContent = $this->removeByteOrderMarkFromJsonString($responseContent);
        return $responseContent;
    }

    public function removeByteOrderMarkFromJsonString($responseContent){
        // Remove the  Byte Order Mark (BOM) from the string
        if (substr($responseContent, 0, 3) === "\xEF\xBB\xBF") {
            $responseContent = substr($responseContent, 3);
        }
        return $responseContent;
    }

    public function getApiDetails(){
        $apiDetails = $this->_helper->getApiCredentials();
        return $apiDetails;
    }

    public function getApiCredentials(){
        $apiDetails = $this->_helper->getApiCredentials();
        $apiCredentials = [
            'apiKey' => $apiDetails['apiLoginId'],
            'transactionKey' => $this->_helper->decryptValue($apiDetails['transactionKey'])
        ];
        return $apiCredentials;
    }

    public function paymentEnvironment()
    {
        $paymentEnvironment = $this->getApiDetails();
        $this->isSandbox = ($paymentEnvironment['accountType'] == 'sandBoxAccount') ? true : false;
    }


    private function _doRequest( string  $uriEndpoint,  string  $requestMethod,  array   $params = [] ): Response 
    {
        $this->paymentEnvironment();
        if($this->isSandbox){
            $apiEndPoint = self::API_REQUEST_URI_SANDBOX;
        }else{
            $apiEndPoint = self::API_REQUEST_URI_LIVE;
        } 
        $client = $this->_clientFactory->create([
            'config' => [
                'base_uri' => $apiEndPoint
                ]
            ]);
        try {
            $response = $client->request(
                $requestMethod,
                $uriEndpoint,
                $params
            );
        } catch (GuzzleException $exception) {
            $response = $this->_responseFactory->create([
                'status' => $exception->getCode(),
                'reason' => $exception->getMessage()
            ]);
            $this->_ApiLogger->info( ' In catch ' . ' <pre> ' . $exception->getMessage() . ' from Ahy\Authorizenet\Service\ApiService ');
        }
        return $response;
    }
}
?>