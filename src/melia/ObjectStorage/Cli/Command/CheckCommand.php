<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class CheckCommand extends BaseCommand
{
    protected static $defaultName = 'check';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Scan store for common issues (missing files, decode errors, expired objects)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        return $this->withStorage($input, function ($storage, string $dir) {
            $issues = [];

            foreach ($storage->list() as $uuid) {
                $meta = $storage->loadMetadata($uuid);
                if (!$meta) {
                    $issues[] = ['uuid' => $uuid, 'issue' => 'metadata_missing'];
                    continue;
                }

                try {
                    $obj = $storage->load($uuid);
                    if ($obj === null && $storage->expired($uuid) === false) {
                        $issues[] = ['uuid' => $uuid, 'issue' => 'data_missing_or_corrupt'];
                    }
                } catch (Throwable $e) {
                    $issues[] = ['uuid' => $uuid, 'issue' => 'load_error', 'message' => $e->getMessage()];
                }

                if ($storage->expired($uuid)) {
                    $issues[] = ['uuid' => $uuid, 'issue' => 'expired'];
                }
            }

            if (!$issues) {
                $this->io->writeln('<ok>✔ Store looks healthy</ok>');
                return 0;
            }

            $this->io->writeln('<warn>⚠ Issues found</warn>');
            foreach ($issues as $i => $it) {
                $this->io->writeln(sprintf(
                    ' <muted>%3d.</muted> <key>%s</key> <warn>%s</warn>%s',
                    $i + 1,
                    $it['uuid'],
                    $it['issue'],
                    isset($it['message']) ? ' <muted>(' . $it['message'] . ')</muted>' : ''
                ));
            }

            return 2;
        });
    }
}