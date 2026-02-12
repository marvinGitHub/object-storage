<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command;

use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Util\Maintenance\ShardRebuilder;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class RebuildShardsCommand extends BaseCommand
{
    protected static $defaultName = 'maintenance:rebuild-shards';
    protected static $defaultDescription = 'Relocates stored objects into the correct shard directories (safe-mode enabled).';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        return $this->withStorage($input, function (ObjectStorage $storage, string $dir): int {
            try {
                $this->io->writeln(sprintf('<info>Rebuilding shards in %s…</info>', $dir));

                $rebuilder = new ShardRebuilder();
                $rebuilder->setStorage($storage);
                $rebuilder->rebuildShards();

                return $this->success('Shards rebuilt');
            } catch (RuntimeException $e) {
                return $this->failure('Rebuild shards failed: ' . $e->getMessage(), 2);
            } catch (Throwable $e) {
                return $this->failure('Rebuild shards failed: ' . $e->getMessage(), 2);
            }
        });
    }
}