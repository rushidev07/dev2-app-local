<?php
/**
 * Copyright © Ahy Consulting All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Ahy\ImageCleanup\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\State;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Ahy\ImageCleanup\Logger\Logger;

class DisableBrokenImages extends Command
{
    const DRY_RUN = 'dry-run';

    /**
     * Active FlxPoint seller IDs — mirrors the list in Ahy\FlxPoint\Helper\Data::getProductParentsDetails()
     * Commented-out entries are intentionally inactive sellers.
     */
    private $sellerIds = [
        // 1088731, // Star-Batt — disabled: qty/prices updated via CSV
        1085637, // Hiden
        1087117, // RecPack
        1050332, // Montana Block Co.
        1050299, // Off the Grid
        // 992297, // Wild Water
        1021473, // True Timber
        1020846, // Lumi
        1015663, // WhiteDuck Outdoors
        995600,  // Near Zero
        995342,  // DryFox
        // 995592, // One Log Fire
        995263,  // Crescent Moon
        // 995581, // Squatch Survival Gear
        // 995579, // Solstice Watersports
        995580,  // Easy Wind Outfitters
        995262,  // Outway
        // 995500, // Magbay Lures
        993457,  // Oru Kayak
        995023,  // Freaks of Nature
        994202,  // Surfside Supply
        992242,  // Pontoon Boat Solutions
        // 991989, // Outsiders
        // 994891, // Hot Bento
        994150,  // Grazly
        // 994017, // Echo Water
        // 993548, // K9 Sport Sack
        // 993175, // MODL Outdoors
        993547,  // Coyote Eyewear
        994262,  // Buck Wipes
        // 993916, // Ice Barrel
        993820,  // Maniac Outdoors
        993626,  // Malo'o
        993628,  // Blue Ribbon Nets
        // 992730, // Fav Fishing
        993627,  // Patriot Coolers
        993458,  // DSG Outerwear
        985348,  // Sasquatch Tea Company
        992751,  // GrandeBass
        // 992254, // Dark Energy
        // 992750, // Simtek
        // 992255, // MonsterBass
        976092,  // Forloh
        988904,  // Evolution Outdoor
        991821,  // Caddis Sports
        975921,  // Grill Your Ass Off
    ];

    /** @var CollectionFactory */
    private $collectionFactory;

    /** @var ProductAction */
    private $productAction;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var State */
    private $appState;

    /** @var Filesystem */
    private $filesystem;

    /** @var Logger */
    private $logger;

    public function __construct(
        CollectionFactory $collectionFactory,
        ProductAction $productAction,
        StoreManagerInterface $storeManager,
        State $appState,
        Filesystem $filesystem,
        Logger $logger
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->productAction     = $productAction;
        $this->storeManager      = $storeManager;
        $this->appState          = $appState;
        $this->filesystem        = $filesystem;
        $this->logger            = $logger;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('ahy:catalog:disable-broken-images');
        $this->setDescription('Disable FlxPoint seller products that have no image or a broken/missing image file');
        $this->addOption(
            self::DRY_RUN,
            'd',
            InputOption::VALUE_NONE,
            'List affected products without disabling them'
        );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dryRun = $input->getOption(self::DRY_RUN);

        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // area code already set
        }

        $mediaDir   = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $baseImgDir = $mediaDir->getAbsolutePath('catalog/product');

        $output->writeln(sprintf(
            '<info>Scanning products from %d active FlxPoint sellers for broken/missing images...</info>',
            count($this->sellerIds)
        ));
        $this->logger->info(sprintf(
            'DisableBrokenImages: scan started%s — seller IDs: %s',
            $dryRun ? ' [dry-run]' : '',
            implode(', ', $this->sellerIds)
        ));

        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect(['name', 'status', 'image', 'small_image', 'thumbnail', 'seller_id'])
                   ->addAttributeToFilter('status', \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
                   ->addAttributeToFilter('seller_id', ['in' => $this->sellerIds]);

        $toDisable = [];

        foreach ($collection as $product) {
            if ($this->hasBrokenImage($product, $baseImgDir)) {
                $toDisable[] = $product->getId();
                $output->writeln(sprintf(
                    '  [ID %s] SKU: %s | Seller: %s | Image: %s',
                    $product->getId(),
                    $product->getSku(),
                    $product->getData('seller_id'),
                    $product->getImage() ?: '(none)'
                ));
                $this->logger->info(sprintf(
                    'Broken/missing image — ID: %s SKU: %s seller_id: %s image: %s',
                    $product->getId(),
                    $product->getSku(),
                    $product->getData('seller_id'),
                    $product->getImage() ?: '(none)'
                ));
            }
        }

        if (empty($toDisable)) {
            $output->writeln('<info>No products with broken or missing images found.</info>');
            $this->logger->info('DisableBrokenImages: no products found, nothing to do.');
            return 0;
        }

        $output->writeln(sprintf('<info>Found %d product(s) with broken/missing images.</info>', count($toDisable)));

        if ($dryRun) {
            $output->writeln('<comment>Dry-run mode: no products were disabled.</comment>');
            $this->logger->info('DisableBrokenImages: dry-run complete, no changes made.');
            return 0;
        }

        $storeId = $this->storeManager->getDefaultStoreView()->getId();
        $this->productAction->updateAttributes(
            $toDisable,
            ['status' => \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_DISABLED],
            $storeId
        );

        $output->writeln('<info>Products disabled successfully.</info>');
        $this->logger->info(sprintf('DisableBrokenImages: disabled %d product(s).', count($toDisable)));

        return 0;
    }

    /**
     * Returns true when a product has no base image, a placeholder value, or the image file does not exist/is invalid.
     */
    private function hasBrokenImage(\Magento\Catalog\Model\Product $product, string $baseImgDir): bool
    {
        $image = $product->getImage();

        if (empty($image) || $image === 'no_selection') {
            return true;
        }

        $absolutePath = $baseImgDir . $image;
        if (!file_exists($absolutePath)) {
            return true;
        }

        if (filesize($absolutePath) === 0) {
            return true;
        }

        $imageInfo = @getimagesize($absolutePath);
        if ($imageInfo === false) {
            return true;
        }

        return false;
    }
}
