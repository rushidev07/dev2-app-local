<?php
namespace Ahy\UpdateProductsCSV\Console\Command;

use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateProducts extends Command
{
    private $appState;

    const FLAG_UPDATE_QUANTITY  = 'quantity';
    const FLAG_UPDATE_CATEGORY  = 'category';
    const FLAG_UPDATE_DESCRIPTION  = 'format-description';
    const FLAG_EXPORT_CATEGORIES = 'export-categories';
    const FLAG_CHANGE_BRAND = 'change-brand';
    const DELETE_SELLER_PRODUCTS = 'delete-seller-products';    
    const COMPRESS_IMAGES = 'image-compression';
    const REMOVE_VARIANT_IMAGES = 'remove-variant-images';
    const BFCM_TEC_APPAREL = 'bfcm-apply-deals';
    const UPDATE_NEW_ARRIVALS = 'new-arrivals';
    const DELETE_PRODUCTS = 'delete-products';

    public function __construct(State $appState)
    {
        $this->appState = $appState;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('ahy:update-products')
            ->setDescription('Update product prices/quantities or categories from CSV files')
            ->addOption(
                self::FLAG_UPDATE_QUANTITY,
                null,
                InputOption::VALUE_NONE,
                'Update product prices and quantities'
            )
            ->addOption(
                self::FLAG_UPDATE_CATEGORY,
                null,
                InputOption::VALUE_NONE,
                'Update product categories'
            )
            ->addOption(
                self::FLAG_UPDATE_DESCRIPTION,
                null,
                InputOption::VALUE_NONE,
                'Update product descpription'
            )
            ->addOption(
                self::FLAG_CHANGE_BRAND,
                null,
                InputOption::VALUE_NONE,
                'Change Brand'
            )
            ->addOption(
                self::FLAG_EXPORT_CATEGORIES,
                null,
                InputOption::VALUE_NONE,
                'Export categories to JSON'
            )
            ->addOption(
                self::DELETE_SELLER_PRODUCTS,
                null,
                InputOption::VALUE_NONE,
                'Delete products from seller'
            )
            ->addOption(
                self::COMPRESS_IMAGES,
                null,
                InputOption::VALUE_NONE,
                'Compress images in klevu-images/flxpoint-images'
            )
            ->addOption(
                self::REMOVE_VARIANT_IMAGES,
                null,
                InputOption::VALUE_NONE,
                'Only use to remove duplicate varaint images'
            )
            ->addOption(
                self::BFCM_TEC_APPAREL,
                null,
                InputOption::VALUE_NONE,
                'Black friday and CyberMonday discounts - NOTE: Take backups for the products'
            )
            ->addOption(
                self::UPDATE_NEW_ARRIVALS,
                null,
                InputOption::VALUE_NONE,
                'Updating new arrivals category...'
            )
            ->addOption(
                self::DELETE_PRODUCTS,
                null,
                InputOption::VALUE_NONE,
                'Delete selected products'
            )
            ;
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            if (!$this->appState->getAreaCode()) {
                $this->appState->setAreaCode('adminhtml');
            }
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $output->writeln("<info>Area code already set.</info>");
        }

        $basePath = BP . '/app/code/Ahy/UpdateProductsCSV/custom-script/';
        $executed = false;

        // Run quantity/price update script
        if ($input->getOption(self::FLAG_UPDATE_QUANTITY)) {
            $scriptPath = $basePath . 'update_products_quantities_prices.php';
            if (file_exists($scriptPath)) {
                $output->writeln("<info>Running quantities/prices update script...</info>");
                $result = shell_exec("php $scriptPath");
                $output->writeln("<info>Output:</info>\n" . $result);
            } else {
                $output->writeln("<error>Script not found: $scriptPath</error>");
            }
            $executed = true;
        }

        // Run category update script
        if ($input->getOption(self::FLAG_UPDATE_CATEGORY)) {
            $scriptPath = $basePath . 'update_products_categories.php';
            if (file_exists($scriptPath)) {
                $output->writeln("<info>Running category update script...</info>");
                $result = shell_exec("php $scriptPath");
                $output->writeln("<info>Output:</info>\n" . $result);
            } else {
                $output->writeln("<error>Script not found: $scriptPath</error>");
            }
            $executed = true;
        }

        if ($input->getOption(self::FLAG_UPDATE_DESCRIPTION)) {
            $scriptPath = $basePath . 'update_products_description.php';
            if (file_exists($scriptPath)) {
                $output->writeln("<info>Running description update script...</info>");
                $result = shell_exec("php $scriptPath");
                $output->writeln("<info>Output:</info>\n" . $result);
            } else {
                $output->writeln("<error>Script not found: $scriptPath</error>");
            }
            $executed = true;
        }

        if ($input->getOption(self::FLAG_CHANGE_BRAND)) {
            $scriptPath = $basePath . 'brandchange.php';
            if (file_exists($scriptPath)) {
                $output->writeln("<info>Running brand chnage script...</info>");
                $result = shell_exec("php $scriptPath");
                $output->writeln("<info>Output:</info>\n" . $result);
            } else {
                $output->writeln("<error>Script not found: $scriptPath</error>");
            }
            $executed = true;
        }

        if ($input->getOption(self::FLAG_EXPORT_CATEGORIES)) {
            $scriptPath = $basePath . 'export_categories.php';
            if (file_exists($scriptPath)) {
                $output->writeln("<info>Exporting categories to JSON...</info>");
                $result = shell_exec("php $scriptPath");
                $output->writeln("<info>Output:</info>\n" . $result);
            } else {
                $output->writeln("<error>Script not found: $scriptPath</error>");
            }
            $executed = true;
        }

        if ($input->getOption(self::DELETE_SELLER_PRODUCTS)) {
            $scriptPath = $basePath . 'delete_seller_products.php';
            if (file_exists($scriptPath)) {
                $output->writeln("<info>Deleting Products...</info>");
                $result = shell_exec("php $scriptPath");
                $output->writeln("<info>Output:</info>\n" . $result);
            } else {
                $output->writeln("<error>Script not found: $scriptPath</error>");
            }
            $executed = true;
        }

        if ($input->getOption(self::COMPRESS_IMAGES)) {
            $scriptPath = $basePath . 'image-compression.php';
            if (file_exists($scriptPath)) {
                $output->writeln("<info>Running image compression script...</info>");
                $result = shell_exec("php $scriptPath 2>/dev/null");
                $output->writeln("<info>Output:</info>\n" . $result);
            } else {
                $output->writeln("<error>Script not found: $scriptPath</error>");
            }
            $executed = true;
        }

        if ($input->getOption(self::REMOVE_VARIANT_IMAGES)) {
            $scriptPath = $basePath . 'remove_variant_images.php';
            if (file_exists($scriptPath)) {
                $output->writeln("<info>Starting remove variant images script...</info>");
                $result = shell_exec("php $scriptPath 2>/dev/null");
                $output->writeln("<info>Output:</info>\n" . $result);
            } else {
                $output->writeln("<error>Script not found: $scriptPath</error>");
            }
            $executed = true;
        }
        if ($input->getOption(self::BFCM_TEC_APPAREL)) {
            $scriptPath = $basePath . 'bfmc-applydeals.php';
            if (file_exists($scriptPath)) {
                $output->writeln("<info>Uploading BFCM discounts ...</info>");
                $result = shell_exec("php $scriptPath 2>/dev/null");
                $output->writeln("<info>Output:</info>\n" . $result);
            } else {
                $output->writeln("<error>Script not found: $scriptPath</error>");
            }
            $executed = true;
        }
        if ($input->getOption(self::UPDATE_NEW_ARRIVALS)) {
            $scriptPath = $basePath . 'update-new-arrivals.php';
            if (file_exists($scriptPath)) {
                $output->writeln("<info>Updating new arrivals category ...</info>");
                $result = shell_exec("php $scriptPath 2>/dev/null");
                $output->writeln("<info>Output:</info>\n" . $result);
            } else {
                $output->writeln("<error>Script not found: $scriptPath</error>");
            }
            $executed = true;
        }
        if ($input->getOption(self::DELETE_PRODUCTS)) {
            $scriptPath = $basePath . 'delete_products.php';
            if (file_exists($scriptPath)) {
                $output->writeln("<info>Delete Selected products to resolve import issues...</info>");
                $result = shell_exec("php $scriptPath");
                $output->writeln("<info>Output:</info>\n" . $result);
            } else {
                $output->writeln("<error>Script not found: $scriptPath</error>");
            }
            $executed = true;
        }
        if (!$executed) {
            $output->writeln("<comment>No option provided. Use --help for available flags.</comment>");
        }

        return 0;
    }
}