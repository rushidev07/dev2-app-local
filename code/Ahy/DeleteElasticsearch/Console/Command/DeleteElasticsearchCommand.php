<?php

namespace Ahy\DeleteElasticsearch\Console\Command;

use Ahy\DeleteElasticsearch\Cron\DeleteIndices;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteElasticsearchCommand extends Command
{
    private DeleteIndices $deleteIndices;
    private State $appState;

    public function __construct(DeleteIndices $deleteIndices, State $appState)
    {
        $this->deleteIndices = $deleteIndices;
        $this->appState = $appState;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('ahy:elasticsearch:delete')
             ->setDescription('Delete Elasticsearch indices manually.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->appState->setAreaCode('adminhtml'); // Prevents area code error
        } catch (LocalizedException $e) {
            // Already set, ignore
        }

        $output->writeln("<info>Starting deletion of Elasticsearch indices...</info>");

        try {
            $this->deleteIndices->execute();
            $output->writeln("<info>Done.</info>");
        } catch (\Exception $e) {
            $output->writeln("<error>Error: " . $e->getMessage() . "</error>");
        }

        return 0;
    }
}
