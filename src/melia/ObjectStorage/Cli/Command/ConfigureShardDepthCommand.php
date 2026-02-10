<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command;

use melia\ObjectStorage\Cli\Config\CliConfig;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class ConfigureShardDepthCommand extends BaseCommand
{
    protected static $defaultName = 'config:shard-depth';
    protected static $defaultDescription = 'Configure a per-storage-dir shard depth for CLI commands (stored centrally).';

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('depth', InputArgument::OPTIONAL, 'Shard depth to store for this --dir (omit to show current setting)')
            ->addOption('unset', null, InputOption::VALUE_NONE, 'Remove shard depth config for this --dir')
            ->addOption('show-path', null, InputOption::VALUE_NONE, 'Show the path to the central CLI config file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        $dir = (string)$input->getOption('dir');
        $depthArg = $input->getArgument('depth');
        $unset = (bool)$input->getOption('unset');
        $showPath = (bool)$input->getOption('show-path');

        $cfg = new CliConfig();

        try {
            if ($showPath) {
                $this->io->writeln($cfg->getConfigPath());
                return 0;
            }

            if ($unset) {
                $cfg->unsetShardDepthForDir($dir);
                return $this->success('Shard depth config removed for ' . $dir);
            }

            if ($depthArg === null) {
                $current = $cfg->getShardDepthForDir($dir);
                if ($current === null) {
                    $this->io->writeln('<muted>No shard depth configured for this directory.</muted>');
                    return 0;
                }

                $this->io->writeln((string)$current);
                return 0;
            }

            if (!is_numeric($depthArg)) {
                throw new RuntimeException('Depth must be an integer.');
            }

            $depth = (int)$depthArg;
            if ($depth <= 0) {
                throw new RuntimeException('Depth must be > 0.');
            }

            // Validation of allowed range is ultimately done by the strategy when applied.
            $cfg->setShardDepthForDir($dir, $depth);

            return $this->success(sprintf('Shard depth set to %d for %s', $depth, $dir));
        } catch (Throwable $e) {
            return $this->failure('Config failed: ' . $e->getMessage(), 2);
        }
    }
}