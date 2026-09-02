<?php

/**
 * Copyright � Ahy Consulting All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Ahy\BarcodeLookup\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Ahy\BarcodeLookup\Helper\CreateCsvFromApiJsonFile;
use Ahy\BarcodeLookup\Helper\CreateJsonFile;
use Ahy\BarcodeLookup\Helper\CreateSqlFileFromCsv;
use Ahy\BarcodeLookup\Helper\CreateCsvForMagentoProducts;
use Magento\Framework\Filesystem\DirectoryList;
use Ahy\BarcodeLookup\Service\GetProductDetails;
use Ahy\BarcodeLookup\Logger\Logger as BarcodeLookupApiLogger;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Data extends AbstractHelper
{

    /**
     * @var CollectionFactory
     */
    private $productCollectionFactory;
    private $createCsvFile;
    private $createJsonFile;
    private $createSqlFile;
    private $createCsvForMagentoProducts;

    protected $scopeConfig;
    protected $eavConfig;
    /**
     * @var DirectoryList
     */
    private $directoryList;
    /**
     * @var GetProductDetails
     */
    private $getProductDetails;
    /**
     * @var BarcodeLookupApiLogger
     */
    private $_barcodeLookupApiLogger;

    private $_processedFolder = '/var/BarcodeLookup/Import-Process/product-catalog/processed/';

    /**
     * @param Context $context
     * @param CollectionFactory $productCollectionFactory
     * @param CreateCsvFromApiJsonFile $createCsvFile
     * @param CreateJsonFile $createJsonFile
     * @param CreateSqlFileFromCsv $createSqlFile
     * @param CreateCsvForMagentoProducts $createCsvForMagentoProducts
     * @param DirectoryList $directoryList
     * @param BarcodeLookupApiLogger $barcodeLookupApiLogger
     * @param GetProductDetails $getProductDetails
     */
    public function __construct(
        Context $context,
        CollectionFactory $productCollectionFactory,
        CreateCsvFromApiJsonFile $createCsvFile,
        CreateJsonFile $createJsonFile,
        CreateSqlFileFromCsv $createSqlFile,
        CreateCsvForMagentoProducts $createCsvForMagentoProducts,
        DirectoryList $directoryList,
        BarcodeLookupApiLogger $barcodeLookupApiLogger,
        GetProductDetails $getProductDetails,
        ScopeConfigInterface $scopeConfig,
        EavConfig $eavConfig
    ) {
        parent::__construct($context);
        $this->productCollectionFactory = $productCollectionFactory;
        $this->createCsvFile = $createCsvFile;
        $this->createJsonFile = $createJsonFile;
        $this->createSqlFile = $createSqlFile;
        $this->createCsvForMagentoProducts = $createCsvForMagentoProducts;
        $this->directoryList = $directoryList;
        $this->_barcodeLookupApiLogger = $barcodeLookupApiLogger;
        $this->getProductDetails = $getProductDetails;
        $this->scopeConfig = $scopeConfig;
        $this->eavConfig = $eavConfig;
    }


    /**
     * The logInfo function logs an informational message using the barcode lookup API logger.
     *
     * @param string message The `logInfo` function takes a string parameter named ``, which is
     * used as the message to be logged by the `_barcodeLookupApiLogger`.
     */
    public function logInfo(string $message): void
    {
        $this->_barcodeLookupApiLogger->info($message);
    }

    /**
     * The logError function logs an error message using the _barcodeLookupApiLogger object.
     *
     * @param string message The `logError` function takes a string parameter named ``, which
     * is the error message that you want to log. This message will be passed to the
     * `_barcodeLookupApiLogger` object's `error` method for logging purposes.
     */
    public function logError(string $message): void
    {
        $this->_barcodeLookupApiLogger->error($message);
    }

    /**
     * The function creates a SQL file from a CSV file and returns an array containing the paths to
     * both files.
     *
     * @return array An array is being returned with keys 'csv' and 'sql' containing the processed CSV
     * file and processed SQL file paths, respectively.
     */
    public function createSqlFileFromCsvFile(): array
    {
        try {
            $csvFile = $this->getProcessedCsvFromApiJsonFile();
            $sqlFile = $this->getProcessedSqlFile();
            // Check if the CSV file exists and is not empty
            if (!file_exists(filename: $csvFile)) {
                throw new \Exception(message: 'CSV file does not exist: ' . $csvFile);
            }

            if (filesize(filename: $csvFile) === 0) {
                throw new \Exception(message: 'CSV file is empty: ' . $csvFile);
            }

            $imagePathCsvFile = $this->getBarcodeImagePathCsvFile();
            $this->createSqlFile->createSqlFileFromCsv( $csvFile,  $sqlFile, $imagePathCsvFile);

            return ['status' => 'success', 'message' => 'SQL file created successfully from CSV file'];
        } catch (\Exception $e) {
            $errorMessage   = $e->getMessage();
            $trace = debug_backtrace();
            $caller = $trace[0];
            $this->logError(message: 'Error in function ' . __FUNCTION__ . ' on line ' . $caller['line'] . ': ' . $errorMessage);
            return ['status' => 'error', 'message' => 'Error when creating SQL file from CSV.'];
        }
    }

    public function getProductsWithUpc(): array
    {
        try {
            $interval = $this->scopeConfig->getValue(
                'barcode_lookup/general/update_interval',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );
            $skus = ['bil-656813114573-1', 'bil-810098420716-1', 'bil-656813114580-1', 'bil-672294601036-1', 'bil-850042107676-1', 'bil-030317036652-1'];

            $returnMsgArr = [];
            $returnMsgArr['status'] = 'error';
            $returnMsgArr['message'] = 'No products found with UPC Number';

            $productCollection = $this->productCollectionFactory->create();
            $attributeToSelect = 'upc_number';

            // $productCollection->addAttributeToSelect('*');
            $productCollection->addAttributeToSelect(['sku', 'name', $attributeToSelect, 'visibility', 'barcode_last_update']); // Only select needed attributes

            $productCollection->addAttributeToFilter($attributeToSelect, ['notnull' => true]);
            $productCollection->addAttributeToFilter($attributeToSelect, ['neq' => '']);
            $productCollection->addAttributeToFilter('type_id', ['eq' => 'simple']);
            $productCollection->addAttributeToFilter('status', ['eq' => 1]);
            $productCollection->addAttributeToFilter('price', ['lteq' => 99999]);
        if (!empty($skus)) {
                $productCollection->addAttributeToFilter('sku', ['in' => $skus]);
            }


            // Check if 'barcode_last_update' attribute exists
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, 'barcode_last_update');

            if ($attribute && $attribute->getId()) {
                $interval = is_numeric($interval) ? (int) $interval : 30;
                $cutoffDate = (new \DateTime())->modify("-{$interval} days")->format('Y-m-d H:i:s');

                $productCollection->addAttributeToFilter(
                    [
                        ['attribute' => 'barcode_last_update', 'null' => true], // case 1: not set
                        ['attribute' => 'barcode_last_update', 'lt' => $cutoffDate] // case 2: older than 30 days
                    ]
                );
            }

            $collection = $productCollection->getItems(); // Get the collection of products from the collection factory

            // Convert collection to array
            $collection = array_map(callback: function ($product): mixed {
                return $product->getData();
            }, array: $collection);

            // If products are found, update status
            if (count($collection) > 0) {
                $returnMsgArr['status'] = 'success';
                $returnMsgArr['message'] = count($collection) . ' products met our Barcode implementation conditions and valid delta!';
            } else {
                $returnMsgArr['status'] = 'No error';
            }

            $returnMsgArr[] = $this->createCsvForMagentoProducts(collection: $collection);
            return $returnMsgArr;  // Return the array so we can process or print later.
        } catch (\Exception $e) {
            $errorMessage   = $e->getMessage();
            $trace = debug_backtrace();
            $caller = $trace[0];
            $this->logError(message: 'Error in function ' . __FUNCTION__ . ' on line ' . $caller['line'] . ': ' . $errorMessage);
            return ['status' => 'error', 'message' => 'Error fetching products with UPC Number'];
        }
    }

    public function getUpcsFromCsv(): array
    {
        try {
            $csvFile = $this->getMagentoProductsCsvFile();
            $upcsArr = [];

            if (($handle = fopen(filename: $csvFile, mode: 'r')) !== false) {
                // Skip the header row
                fgetcsv(stream: $handle);

                // Read the rest of the rows
                while (($data = fgetcsv(stream: $handle)) !== false) {
                    // Assuming the UPC is in the fourth column (index 3)
                    if (isset($data[3]) && !empty($data[3])) { // Check if the UPC is not empty
                        $upcsArr[] = $data[3]; // Add the UPC to the array
                    }
                }
                
                // 19th May: Even though this is a waste of making API request (as anyway the product with UPC trailing spaces isn't going to be updated in DB), this will avoid conflicting with other UPCs in the list when making API request.

                /* // Read the rest of the rows
                while (($data = fgetcsv(stream: $handle)) !== false) {
                    $upc = trim(str_replace("\u{00A0}", '', $data[3]));

                    // Assuming the UPC is in the fourth column (index 3)
                    if (isset($data[3])) {
                        $upc = trim(str_replace("\u{00A0}", '', $data[3]));  // explicitly replace non-breaking spaces first, then trim.

                        if (!empty($upc)) {
                            $upcsArr[] = $upc;  // Add the UPC to the array
                        }
                    }
                } */

                fclose(stream: $handle);
            }
            
            // Remove duplicates and sort the array
            // $upcsArr = array_unique(array: $upcsArr); // Remove duplicates
            // sort(array: $upcsArr);

            // Split the UPCsArr array into chunks of 10
            $upcsChunks = array_chunk(array: $upcsArr, length: 10); // Get the UPCsArr array in chunks of 10 chunks separated by commas between each chunk of the UPCsArr array

            // Convert each chunk to a comma-separated string
            $upcsChunks = array_map(callback: function ($chunk): string {
                return implode(separator: ',', array: $chunk);
            }, array: $upcsChunks); // array keys are preserved as they are returned from array_map function call to avoid duplicates and performance issues with arrays with large number of elements returned from array_map

            return $upcsChunks;
        } catch (\Exception $e) {
            $errorMessage   = $e->getMessage();
            $trace = debug_backtrace();
            $caller = $trace[0];
            $this->logError('Error in function ' . __FUNCTION__ . ' on line ' . $caller['line'] . ': ' . $errorMessage);
            return [];
        }
    }


        public function createJsonFileFromCsvFile(): array
        {
            try {
                // Hardcoded UPCs
                $upcs = [
                    '023614780243',
                    '023614780250',
                    '030317036652',
                    '656813114573',
                    '656813114580',
                    '672294601036'
                ];

                // Combine into chunks if needed, here all together for simplicity
                $upcChunks = array_chunk($upcs, 10); // API can handle multiple UPCs per request
                $upcChunks = array_map(fn($chunk) => implode(',', $chunk), $upcChunks);

                $responseContent = [
                    'response' => [],
                    'error' => [],
                    'message' => ''
                ];

                foreach ($upcChunks as $index => $upcChunk) {
                    $this->logInfo("Requesting API for UPC(s): " . $upcChunk);

                    $apiResponse = $this->getProductDetails->getProductData(upcs: $upcChunk);
                    $status = $apiResponse['status'];

                    // Fix API response UPC mismatch
                    $upcArray = explode(',', $upcChunk);
                    $decodedResponse = json_decode($apiResponse['response'], true);
                    if (isset($decodedResponse['products']) && is_array($decodedResponse['products'])) {
                        foreach ($decodedResponse['products'] as $i => &$product) {
                            if (isset($upcArray[$i])) {
                                $product['barcode_number'] = $upcArray[$i];
                            }
                        }
                    }
                    $apiResponse['response'] = json_encode($decodedResponse, JSON_UNESCAPED_SLASHES);

                    // Handle API status
                    switch ($status) {
                        case 200:
                            $responseContent['response'][] = $apiResponse['response'];
                            break;
                        case 404:
                            $responseContent['error'][] = 'Error: UPC(s) not found - ' . $upcChunk;
                            break;
                        default:
                            $responseContent['error'][] = "Error: Status $status for UPC(s) - $upcChunk";
                            break;
                    }

                    // Optional: rate limiting
                    if ($index % 10 === 0 && $index !== 0) {
                        sleep(2);
                    }
                }

                if (!empty($responseContent['response'])) {
                    $jsonFile = $this->getProcessedApiJsonFile();
                    $this->createJsonFile->createJsonFile(response: $responseContent['response'], filepath: $jsonFile);
                } else {
                    $this->logError('No response content to create JSON file');
                }

                return $responseContent;
            } catch (\Exception $e) {
                $this->logError('Error in function ' . __FUNCTION__ . ': ' . $e->getMessage());
                return ['status' => 'error', 'message' => 'Error creating JSON from hardcoded UPCs'];
            }
        }
    public function createCsvFileFromJsonFile(): array
    {
        try {
            $jsonFile = $this->getProcessedApiJsonFile();
            $csvFile = $this->getProcessedCsvFromApiJsonFile();

            // Check if the JSON file exists and is not empty
            if (!file_exists(filename: $jsonFile)) {
                throw new \Exception('JSON file does not exist: ' . $jsonFile);
            }

            if (filesize(filename: $jsonFile) === 0) {
                throw new \Exception('JSON file is empty: ' . $jsonFile);
            }

            return $this->createCsvFile->createCsvFromApiJsonFile(jsonFile: $jsonFile, csvFile: $csvFile);
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $trace = debug_backtrace();
            $caller = $trace[0];
            $this->logError(message: 'Error in function ' . __FUNCTION__ . ' on line ' . $caller['line'] . ': ' . $errorMessage);

            return ['status' => 'error', 'message' => 'Error creating CSV from JSON file: ' . $errorMessage];
        }
    }

    public function createCsvForMagentoProducts($collection): array
    {
        try {
            $products = $collection;
            $csvFile = $this->getMagentoProductsCsvFile();

            $this->createCsvForMagentoProducts->createCsvForMagentoProducts($products, $csvFile);

            return ['csv' => $csvFile];
        } catch (\Exception $e) {
            $errorMessage   = $e->getMessage();
            $trace = debug_backtrace();
            $caller = $trace[0];
            $this->logError(message: 'Error in function ' . __FUNCTION__ . ' on line ' . $caller['line'] . ': ' . $errorMessage);
            return ['status' => 'error', 'message' => 'Error creating CSV for Magento Products. Check Logs for more information. ' . $errorMessage];
        }
    }

    public function getMagentoProductsCsvFile(): string
    {
        try {
            return $this->getBasePath() . $this->_processedFolder . date(format: 'Y-m-d') . '/magento-upc-products' . '.csv';
        } catch (\Exception $e) {
            $errorMessage   = $e->getMessage();
            $trace = debug_backtrace();
            $caller = $trace[0];
            $this->logError(message: 'Error in function ' . __FUNCTION__ . ' on line ' . $caller['line'] . ': ' . $errorMessage);
            return '';
        }
    }

    public function getProcessedApiJsonFile(): string
    {
        try {
            return $this->getBasePath() . $this->_processedFolder . date(format: 'Y-m-d') . '/api-products-response' . '.json';
        } catch (\Exception $e) {
            $errorMessage   = $e->getMessage();
            $trace = debug_backtrace();
            $caller = $trace[0];
            $this->logError(message: 'Error in function ' . __FUNCTION__ . ' on line ' . $caller['line'] . ': ' . $errorMessage);
            return '';
        }
    }

    public function getProcessedCsvFromApiJsonFile(): string
    {
        try {
            return $this->getBasePath() . $this->_processedFolder . date(format: 'Y-m-d') . '/api-products-data' . '.csv';
        } catch (\Exception $e) {
            $errorMessage   = $e->getMessage();
            $trace = debug_backtrace();
            $caller = $trace[0];
            $this->logError(message: 'Error in function ' . __FUNCTION__ . ' on line ' . $caller['line'] . ': ' . $errorMessage);
            return '';
        }
    }

    public function getBarcodeImagePathCsvFile(): string
    {
        try {
            return $this->getBasePath() . $this->_processedFolder . date(format: 'Y-m-d') . '/barcode-product-image.csv';
        } catch (\Exception $e) {
            $errorMessage   = $e->getMessage();
            $trace = debug_backtrace();
            $caller = $trace[0];
            $this->logError(message: 'Error in function ' . __FUNCTION__ . ' on line ' . $caller['line'] . ': ' . $errorMessage);
            return '';
        }
    }

    public function getProcessedJsonFile(): string
    {
        try {
            return $this->getBasePath() . $this->_processedFolder . date(format: 'Y-m-d') . '/product-catalog' . '.json';
        } catch (\Exception $e) {
            $errorMessage   = $e->getMessage();
            $trace = debug_backtrace();
            $caller = $trace[0];
            $this->logError(message: 'Error in function ' . __FUNCTION__ . ' on line ' . $caller['line'] . ': ' . $errorMessage);
            return '';
        }
    }

    public function getProcessedCsvFile(): string
    {
        try {
            return $this->getBasePath() . $this->_processedFolder . date(format: 'Y-m-d') . '/product-catalog' . '.csv';
        } catch (\Exception $e) {
            $errorMessage   = $e->getMessage();
            $trace = debug_backtrace();
            $caller = $trace[0];
            $this->logError(message: 'Error in function ' . __FUNCTION__ . ' on line ' . $caller['line'] . ': ' . $errorMessage);
            return '';
        }
    }

    public function getProcessedSqlFile(): string
    {
        try {
            return $this->getBasePath() . $this->_processedFolder . date(format: 'Y-m-d') . '/update-magento-catalog-products' . '.sql';
        } catch (\Exception $e) {
            $errorMessage   = $e->getMessage();
            $trace = debug_backtrace();
            $caller = $trace[0];
            $this->logError(message: 'Error in function ' . __FUNCTION__ . ' on line ' . $caller['line'] . ': ' . $errorMessage);
            return '';
        }
    }

    protected function getBasePath(): string
    {
        try {
            return $this->directoryList->getRoot();
        } catch (\Exception $e) {
            $errorMessage   = $e->getMessage();
            $trace = debug_backtrace();
            $caller = $trace[0];
            $this->logError(message: 'Error in function ' . __FUNCTION__ . ' on line ' . $caller['line'] . ': ' . $errorMessage);
            return '';
        }
    }
}