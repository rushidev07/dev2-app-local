<?php

namespace Ahy\MissingImage\Console;

use Ahy\MissingImage\Logger\Logger;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webkul\Marketplace\Model\ResourceModel\Seller\CollectionFactory as SellerCollectionFactory;
use Webkul\Marketplace\Model\Seller;

class CheckMissingSellerImages extends Command
{
    const PAGE_SIZE = 500;

    protected ProductCollectionFactory $productCollectionFactory;
    protected SellerCollectionFactory $sellerCollectionFactory;
    protected Logger $logger;
    protected EavConfig $eavConfig;

    public function __construct(
        ProductCollectionFactory $productCollectionFactory,
        SellerCollectionFactory $sellerCollectionFactory,
        Logger $logger,
        EavConfig $eavConfig
    ) {
        $this->productCollectionFactory = $productCollectionFactory;
        $this->sellerCollectionFactory  = $sellerCollectionFactory;
        $this->logger                   = $logger;
        $this->eavConfig                = $eavConfig;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('ahy:seller:check-missing-images');
        $this->setDescription('Reports enabled products with missing base images belonging to active sellers. Results are printed to the console and written to var/log/ActiveSellerMissingImage/missing_image.log.');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $activeSellerIds = $this->getActiveSellerIds();

        if (empty($activeSellerIds)) {
            $message = 'No active sellers found.';
            $output->writeln('<comment>' . $message . '</comment>');
            $this->logger->info('check_missing_images: ' . $message);
            return;
        }

        $startMessage = sprintf('Scanning products for %d active seller(s).', count($activeSellerIds));
        $output->writeln('<info>' . $startMessage . '</info>');
        $this->logger->info('check_missing_images: ' . $startMessage);

        $imageAttributeId = (int) $this->eavConfig
            ->getAttribute(Product::ENTITY, 'image')
            ->getId();

        $currentPage = 1;
        $found       = 0;

        do {
            $collection = $this->productCollectionFactory->create();
            $collection
                ->addAttributeToSelect(['sku', 'name', 'status', 'visibility', 'type_id'])
                ->addAttributeToFilter('status', Status::STATUS_ENABLED)
                ->addAttributeToFilter('visibility', ['neq' => Visibility::VISIBILITY_NOT_VISIBLE])
                ->setPageSize(self::PAGE_SIZE)
                ->setCurPage($currentPage);

            $varcharTable = $collection->getResource()->getTable('catalog_product_entity_varchar');

            $collection->getSelect()
                ->distinct(true)
                ->join(
                    ['mp' => $collection->getResource()->getTable('marketplace_product')],
                    'e.entity_id = mp.mageproduct_id',
                    ['seller_id']
                )
                ->where('mp.seller_id IN (?)', $activeSellerIds)
                ->joinLeft(
                    ['img_val' => $varcharTable],
                    "e.entity_id = img_val.entity_id
                     AND img_val.attribute_id = {$imageAttributeId}
                     AND img_val.value IS NOT NULL
                     AND img_val.value NOT IN ('no_selection', '')",
                    []
                )
                ->where('img_val.entity_id IS NULL');

            $lastPage = $collection->getLastPageNumber();

            foreach ($collection as $product) {
                $line = sprintf(
                    'Missing image — SKU: %s | Title: %s | Seller ID: %s | Type: %s | Status: Enabled',
                    $product->getSku(),
                    $product->getName(),
                    $product->getData('seller_id'),
                    $product->getTypeId()
                );
                $output->writeln($line);
                $this->logger->info($line);
                $found++;
            }

            $currentPage++;
        } while ($currentPage <= $lastPage);

        $doneMessage = sprintf('Complete. %d product(s) missing images.', $found);
        $output->writeln('<info>' . $doneMessage . '</info>');
        $this->logger->info('check_missing_images: ' . $doneMessage);
    }

    private function getActiveSellerIds()
    {
        $sellers = $this->sellerCollectionFactory->create()
            ->addFieldToFilter('is_seller', Seller::STATUS_ENABLED);

        return array_map('intval', $sellers->getColumnValues('seller_id'));
    }
}
