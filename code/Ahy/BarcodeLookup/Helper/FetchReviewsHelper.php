<?php

/**
 * Copyright © Ahy Consulting All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Ahy\BarcodeLookup\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Store\Model\StoreManagerInterface;
use Ahy\BarcodeLookup\Service\GetProductDetails;
use Ahy\BarcodeLookup\Logger\Logger as BarcodeLookupApiLogger;

class FetchReviewsHelper extends AbstractHelper
{
    /**
     * @var CollectionFactory
     */
    private $productCollectionFactory;

    /**
     * @var DirectoryList
     */
    private $directoryList;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var GetProductDetails
     */
    private $getProductDetails;

    /**
     * @var BarcodeLookupApiLogger
     */
    private $logger;

    private const REVIEWS_FOLDER = '/var/BarcodeLookup/Import-Process/product-catalog/processed/';

    private const UPC_CHUNK_SIZE = 10;

    /**
     * CSV headers for the reviews export file.
     */
    private const CSV_HEADERS = [
        'upc',
        'product_id',
        'product_title',
        'product_url',
        'date',
        'review_content',
        'review_score',
        'review_title',
        'display_name',
        'email',
    ];

    /**
     * @param Context $context
     * @param CollectionFactory $productCollectionFactory
     * @param DirectoryList $directoryList
     * @param StoreManagerInterface $storeManager
     * @param GetProductDetails $getProductDetails
     * @param BarcodeLookupApiLogger $logger
     */
    public function __construct(
        Context $context,
        CollectionFactory $productCollectionFactory,
        DirectoryList $directoryList,
        StoreManagerInterface $storeManager,
        GetProductDetails $getProductDetails,
        BarcodeLookupApiLogger $logger
    ) {
        parent::__construct($context);
        $this->productCollectionFactory = $productCollectionFactory;
        $this->directoryList            = $directoryList;
        $this->storeManager             = $storeManager;
        $this->getProductDetails        = $getProductDetails;
        $this->logger                   = $logger;
    }

    /**
     * Main entry point: fetch reviews from BarcodeLookup API for all Magento products
     * that have a UPC number and write the combined data to a CSV file.
     *
     * @return array{status: string, message?: string, csv_path?: string, total_reviews?: int}
     */
    public function fetchAndWriteReviews(): array
    {
        try {
            $magentoProducts = $this->getMagentoProductsByUpc();

            if (empty($magentoProducts)) {
                $this->logger->info('FetchReviewsHelper: No Magento products with UPC found.');
                return ['status' => 'error', 'message' => 'No products with UPC number found in Magento.'];
            }

            $this->logger->info(
                'FetchReviewsHelper: Found ' . count($magentoProducts) . ' products with UPC.'
            );

            $allReviewRows = [];
            $upcs          = array_keys($magentoProducts);
            $upcChunks     = array_chunk($upcs, self::UPC_CHUNK_SIZE);

            foreach ($upcChunks as $index => $chunk) {
                $upcString   = implode(',', $chunk);
                $this->logger->info('FetchReviewsHelper: Requesting API for UPCs: ' . $upcString);

                $apiResponse = $this->getProductDetails->getProductData($upcString);
                $status      = $apiResponse['status'] ?? 0;

                if ($status !== 200) {
                    $this->logger->info(
                        'FetchReviewsHelper: API returned status ' . $status . ' for chunk ' . $index
                    );
                    continue;
                }

                $decodedResponse = json_decode($apiResponse['response'], true);

                if (!isset($decodedResponse['products']) || !is_array($decodedResponse['products'])) {
                    continue;
                }

                foreach ($decodedResponse['products'] as $product) {
                    $barcode = $product['barcode_number'] ?? null;

                    if ($barcode === null || !isset($magentoProducts[$barcode])) {
                        continue;
                    }

                    $magentoProduct = $magentoProducts[$barcode];
                    $reviews        = $product['reviews'] ?? [];

                    foreach ($reviews as $review) {
                        $allReviewRows[] = [
                            'upc'            => $barcode,
                            'product_id'     => $magentoProduct['product_id'],
                            'product_title'  => $magentoProduct['product_title'],
                            'product_url'    => $magentoProduct['product_url'],
                            'date'           => $review['date'] ?? '',
                            'review_content' => $review['review'] ?? '',
                            'review_score'   => $review['rating'] ?? '',
                            'review_title'   => $review['title'] ?? '',
                            'display_name'   => isset($review['name']) && $review['name'] !== ''
                                ? $review['name']
                                : 'anonymous',
                            'email'          => '',
                        ];
                    }
                }

                // Rate-limit: sleep 2 seconds after every 10 API requests
                if (($index + 1) % 10 === 0) {
                    sleep(2);
                }
            }

            $this->logger->info(
                'FetchReviewsHelper: Total review rows collected: ' . count($allReviewRows)
            );

            $csvFile = $this->getReviewsCsvFilePath();
            $this->writeReviewsToCsv($allReviewRows, $csvFile);

            $this->logger->info('FetchReviewsHelper: Reviews CSV written to ' . $csvFile);

            return [
                'status'        => 'success',
                'csv_path'      => $csvFile,
                'total_reviews' => count($allReviewRows),
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                'FetchReviewsHelper: Error in ' . __FUNCTION__ . ': ' . $e->getMessage()
            );
            return ['status' => 'error', 'message' => 'Error fetching reviews: ' . $e->getMessage()];
        }
    }

    /**
     * Build a map of UPC → Magento product data for all active simple products that have a UPC.
     *
     * @return array<string, array{product_id: int|string, product_title: string, product_url: string}>
     */
    private function getMagentoProductsByUpc(): array
    {
        $storeBaseUrl = rtrim($this->storeManager->getStore()->getBaseUrl(), '/');

        $productCollection = $this->productCollectionFactory->create();
        $productCollection->addAttributeToSelect(['name', 'upc_number', 'url_key']);
        $productCollection->addAttributeToFilter('upc_number', ['notnull' => true]);
        $productCollection->addAttributeToFilter('upc_number', ['neq' => '']);
        $productCollection->addAttributeToFilter('type_id', ['eq' => 'simple']);
        $productCollection->addAttributeToFilter('status', ['eq' => 1]);

        $result = [];
        foreach ($productCollection as $product) {
            $upc = (string) $product->getData('upc_number');
            if ($upc === '') {
                continue;
            }

            $urlKey     = (string) $product->getData('url_key');
            $productUrl = $urlKey !== '' ? $storeBaseUrl . '/' . $urlKey . '.html' : '';

            $result[$upc] = [
                'product_id'    => $product->getId(),
                'product_title' => (string) $product->getData('name'),
                'product_url'   => $productUrl,
            ];
        }

        return $result;
    }

    /**
     * Return the absolute path for today's reviews CSV file.
     *
     * @return string
     */
    public function getReviewsCsvFilePath(): string
    {
        return $this->directoryList->getRoot()
            . self::REVIEWS_FOLDER
            . date('Y-m-d')
            . '/product-reviews.csv';
    }

    /**
     * Write review rows to a CSV file, creating directories as needed.
     *
     * @param array  $rows
     * @param string $csvFile
     * @return void
     * @throws \Exception
     */
    private function writeReviewsToCsv(array $rows, string $csvFile): void
    {
        $directory = dirname($csvFile);
        if (!file_exists($directory)) {
            mkdir($directory, 0777, true);
        }

        $fp = fopen($csvFile, 'w');
        if ($fp === false) {
            throw new \Exception('Failed to open file for writing: ' . $csvFile);
        }

        try {
            fputcsv($fp, self::CSV_HEADERS);

            foreach ($rows as $row) {
                fputcsv($fp, [
                    $row['upc']            ?? '',
                    $row['product_id']     ?? '',
                    $row['product_title']  ?? '',
                    $row['product_url']    ?? '',
                    $row['date']           ?? '',
                    $row['review_content'] ?? '',
                    $row['review_score']   ?? '',
                    $row['review_title']   ?? '',
                    $row['display_name']   ?? 'anonymous',
                    $row['email']          ?? '',
                ]);
            }
        } finally {
            fclose($fp);
        }
    }
}