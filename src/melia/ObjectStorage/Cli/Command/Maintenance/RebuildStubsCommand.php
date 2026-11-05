<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command\Maintenance;

use melia\ObjectStorage\Cli\Command\BaseCommand;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Util\Maintenance\StubRebuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'maintenance:rebuild-stubs')]
final class RebuildStubsCommand extends BaseCommand
{
    protected static $defaultDescription = 'Rebuilds the class index (stubs) in place; continues an interrupted rebuild.';

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Objects or stubs processed per step', (string)StubRebuilder::DEFAULT_BATCH_SIZE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);
        $batchSize = max(1, (int)$input->getOption('batch-size'));

        return $this->withStorage($input, function (ObjectStorage $storage, string $dir) use ($batchSize): int {
            $bar = null;

            try {
                $rebuilder = new StubRebuilder();
                $rebuilder->setStorage($storage);

                $this->io->writeln(sprintf(
                    '<info>%s stubs in %s…</info>',
                    $rebuilder->isRunning() ? 'Continuing the rebuild of the' : 'Rebuilding',
                    $dir
                ));

                $progress = $rebuilder->rebuildStubs(function (array $progress) use (&$bar): void {
                    if (null === $bar) {
                        $bar = $this->io->createProgressBar($progress['total']);
                        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %message%');
                        $bar->setBarWidth(28);
                        $bar->start();
                    }
                    // the message first: setProgress() redraws the bar
                    // short, so the bar keeps its width in a terminal of 80 columns
                    $bar->setMessage(sprintf(
                        '%s: +%d -%d%s',
                        $progress['phase'] ?? 'done',
                        $progress['created'],
                        $progress['removed'],
                        $progress['corrupted'] > 0 ? sprintf(', %d corrupted', $progress['corrupted']) : ''
                    ));
                    $bar->setProgress($progress['done'] ? $bar->getMaxSteps() : $progress['processed']);
                }, $batchSize);

                if ($bar instanceof ProgressBar) {
                    $bar->finish();
                    $this->io->newLine(2);
                }

                return $this->success(sprintf(
                    'Stubs rebuilt: %d created, %d removed, %d corrupted objects moved aside',
                    $progress['created'],
                    $progress['removed'],
                    $progress['corrupted']
                ));
            } catch (Throwable $e) {
                if ($bar instanceof ProgressBar) {
                    $this->io->newLine(2);
                }
                return $this->failure('Rebuild stubs failed: ' . $e->getMessage() . ' Run the command again to continue.', 2);
            }
        });
    }
}
