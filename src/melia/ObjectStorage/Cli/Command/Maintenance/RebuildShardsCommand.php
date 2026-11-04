<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command\Maintenance;

use melia\ObjectStorage\Cli\Command\BaseCommand;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Util\Maintenance\ShardRebuilder;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class RebuildShardsCommand extends BaseCommand
{
    protected static $defaultName = 'maintenance:rebuild-shards';
    protected static $defaultDescription = 'Relocates stored objects into the correct shard directories (safe-mode enabled); continues an interrupted rebuild.';

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Files moved per step', (string)ShardRebuilder::DEFAULT_BATCH_SIZE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);
        $batchSize = max(1, (int)$input->getOption('batch-size'));

        return $this->withStorage($input, function (ObjectStorage $storage, string $dir) use ($batchSize): int {
            $bar = null;

            try {
                $rebuilder = new ShardRebuilder();
                $rebuilder->setStorage($storage);

                $this->io->writeln(sprintf(
                    '<info>%s shards in %s…</info>',
                    $rebuilder->isRunning() ? 'Continuing the rebuild of the' : 'Rebuilding',
                    $dir
                ));

                $progress = $rebuilder->rebuildShards(function (array $progress) use (&$bar): void {
                    if (null === $bar) {
                        $bar = $this->io->createProgressBar($progress['total']);
                        $bar->setFormat(' %current%/%max% objects [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %message%');
                        $bar->setBarWidth(28);
                        $bar->setMessage(sprintf('depth %s', $progress['depth'] ?? '?'));
                        $bar->start();
                    }
                    // the message first: setProgress() redraws the bar
                    $bar->setMessage(sprintf('%d files moved', $progress['moved']));
                    $bar->setProgress($progress['done'] ? $bar->getMaxSteps() : $progress['processed']);
                }, $batchSize);

                if ($bar instanceof ProgressBar) {
                    $bar->finish();
                    $this->io->newLine(2);
                }

                return $this->success(sprintf('Shards rebuilt: %d files moved', $progress['moved']));
            } catch (Throwable $e) {
                if ($bar instanceof ProgressBar) {
                    $this->io->newLine(2);
                }
                return $this->failure('Rebuild shards failed: ' . $e->getMessage() . ' The storage stays in safe mode; run the command again to continue.', 2);
            }
        });
    }
}
