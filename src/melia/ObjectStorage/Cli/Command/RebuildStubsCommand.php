<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command;

use melia\ObjectStorage\ObjectStorage;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class RebuildStubsCommand extends BaseCommand
{
    protected static $defaultName = 'maintenance:rebuild-stubs';
    protected static $defaultDescription = 'Rebuilds stub data for stored objects (safe-mode enabled if supported).';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        return $this->withStorage($input, function (ObjectStorage $storage, string $dir): int {
            try {
                $this->io->writeln(sprintf('<info>Rebuilding stubs in %s…</info>', $dir));

                // Defensive: keep CLI stable if storage implementation differs.
                if (!method_exists($storage, 'rebuildStubs')) {
                    return $this->failure(
                        'Storage does not support rebuildStubs(). Add ObjectStorage::rebuildStubs() or adjust this command.',
                        1
                    );
                }

                $storage->getStateHandler()?->enableSafeMode();
                try {
                    $storage->rebuildStubs();
                } finally {
                    $storage->getStateHandler()?->disableSafeMode();
                }

                return $this->success('Stubs rebuilt');
            } catch (RuntimeException $e) {
                return $this->failure('Rebuild stubs failed: ' . $e->getMessage(), 2);
            } catch (Throwable $e) {
                return $this->failure('Rebuild stubs failed: ' . $e->getMessage(), 2);
            }
        });
    }
}