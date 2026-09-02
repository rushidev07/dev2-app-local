<?php

/**
 * Copyright © Ahy Consulting All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Ahy\BarcodeLookup\Console\Command;

use Ahy\BarcodeLookup\Helper\FetchReviewsHelper;
use Ahy\BarcodeLookup\Logger\Logger as BarcodeLookupApiLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FetchReviews extends Command
{
    /**
     * @var FetchReviewsHelper
     */
    private $fetchReviewsHelper;

    /**
     * @var BarcodeLookupApiLogger
     */
    private $logger;

    /**
     * @param FetchReviewsHelper     $fetchReviewsHelper
     * @param BarcodeLookupApiLogger $logger
     */
    public function __construct(
        FetchReviewsHelper $fetchReviewsHelper,
        BarcodeLookupApiLogger $logger
    ) {
        $this->fetchReviewsHelper = $fetchReviewsHelper;
        $this->logger             = $logger;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->setName('ahy:barcodelookup:fetch-reviews');
        $this->setDescription(
            'Fetch product reviews from the BarcodeLookup API for all Magento products '
            . 'that have a UPC number and export them to a CSV file. '
            . 'The CSV contains: upc, product_id, product_title, product_url, date, '
            . 'review_content, review_score, review_title, display_name, email.'
        );
        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Starting BarcodeLookup reviews fetch...</info>');
        $this->logger->info('FetchReviews command: started.');

        $result = $this->fetchReviewsHelper->fetchAndWriteReviews();

        if (($result['status'] ?? '') === 'success') {
            $output->writeln(
                '<info>Reviews CSV created successfully: ' . ($result['csv_path'] ?? '') . '</info>'
            );
            $output->writeln(
                '<info>Total reviews exported: ' . ($result['total_reviews'] ?? 0) . '</info>'
            );
            $this->logger->info(
                'FetchReviews command: completed. CSV: '
                . ($result['csv_path'] ?? '') . ', reviews: ' . ($result['total_reviews'] ?? 0)
            );
            return Command::SUCCESS;
        }

        $errorMessage = $result['message'] ?? 'An unknown error occurred.';
        $output->writeln('<error>' . $errorMessage . '</error>');
        $this->logger->error('FetchReviews command: failed. ' . $errorMessage);
        return Command::FAILURE;
    }
}