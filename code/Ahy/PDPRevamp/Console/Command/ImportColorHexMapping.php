<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Console\Command;

use Ahy\PDPRevamp\Service\AiColorHexResolver;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One-time bulk backfill for `color` swatch options that AiColorHexResolver's
 * live Gemini lookup couldn't reasonably resolve on its own (mostly creative
 * marketing/pattern names like "Deep Cover" or "Chartreuse Black Back" -
 * fishing lure and hunting-camo lines). Reads a precomputed label => hex
 * mapping (single "#RRGGBB" or dual "#RRGGBB,#RRGGBB") from
 * Data/color-hex-mapping.json and applies it to every currently-missing
 * option via AiColorHexResolver::savePrecomputedHex() - no live AI calls.
 */
class ImportColorHexMapping extends Command
{
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_FILE = 'file';

    private State $appState;
    private AiColorHexResolver $resolver;
    private ModuleDirReader $moduleDirReader;

    public function __construct(
        State $appState,
        AiColorHexResolver $resolver,
        ModuleDirReader $moduleDirReader
    ) {
        $this->appState = $appState;
        $this->resolver = $resolver;
        $this->moduleDirReader = $moduleDirReader;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('ahy:pdp:import-color-hex-mapping')
            ->setDescription('Apply a precomputed color-name => hex mapping to every `color` option missing a native swatch')
            ->addOption(
                self::OPTION_FILE,
                null,
                InputOption::VALUE_REQUIRED,
                'Path to the mapping JSON file (label => hex). Default: Data/color-hex-mapping.json in this module.'
            )
            ->addOption(
                self::OPTION_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Print what would be saved without writing anything'
            );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            if (!$this->appState->getAreaCode()) {
                $this->appState->setAreaCode('adminhtml');
            }
        } catch (LocalizedException $e) {
            // Area code already set - fine.
        }

        $filePath = $input->getOption(self::OPTION_FILE)
            ?: $this->moduleDirReader->getModuleDir('', 'Ahy_PDPRevamp') . '/Data/color-hex-mapping.json';

        if (!is_readable($filePath)) {
            $output->writeln("<error>Mapping file not found or not readable: $filePath</error>");
            return 1;
        }

        $mapping = json_decode((string) file_get_contents($filePath), true);
        if (!is_array($mapping)) {
            $output->writeln("<error>Mapping file is not valid JSON: $filePath</error>");
            return 1;
        }

        $dryRun = (bool) $input->getOption(self::OPTION_DRY_RUN);
        $missing = $this->resolver->getMissingColorOptions();

        $output->writeln('<info>Loaded ' . count($mapping) . ' mapping entries from ' . $filePath . '</info>');
        $output->writeln('<info>Found ' . count($missing) . ' color option(s) missing a swatch.</info>');
        $output->writeln('<info>Mode: ' . ($dryRun ? 'DRY RUN (no writes)' : 'LIVE (will write to eav_attribute_option_swatch)') . '</info>');
        $output->writeln('');

        $resolved = 0;
        $unmapped = [];

        foreach ($missing as $optionId => $label) {
            if (!array_key_exists($label, $mapping)) {
                $unmapped[] = "[$optionId] \"$label\"";
                continue;
            }

            $hex = $mapping[$label];
            if ($dryRun) {
                $output->writeln("[$optionId] \"$label\" -> $hex (dry-run)");
            } elseif ($this->resolver->savePrecomputedHex($optionId, $hex)) {
                $output->writeln("[$optionId] \"$label\" -> $hex (saved)");
            } else {
                $output->writeln("<comment>[$optionId] \"$label\": invalid hex \"$hex\" in mapping - skipped.</comment>");
                continue;
            }
            $resolved++;
        }

        $output->writeln('');
        $output->writeln('<info>Resolved: ' . $resolved . '</info>');
        $output->writeln('<info>Unmapped (no entry in mapping file, left untouched): ' . count($unmapped) . '</info>');
        if (!empty($unmapped)) {
            $output->writeln(implode("\n", $unmapped));
        }

        return 0;
    }
}


