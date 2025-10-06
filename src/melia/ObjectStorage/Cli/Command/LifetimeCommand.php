<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class LifetimeCommand extends BaseCommand
{
    protected static $defaultName = 'ttl';

    protected function configure(): void
    {
        parent::configure();
        $this
            ->setDescription('Get or set TTL for an object by UUID')
            ->addArgument('uuid', InputArgument::REQUIRED, 'UUID')
            ->addOption('set', 's', InputOption::VALUE_REQUIRED, 'Set TTL in seconds (use 0 to remove expiration)')
            ->addOption('pretty', 'p', InputOption::VALUE_NONE, 'Pretty-print JSON when using --json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        $uuid = (string)$input->getArgument('uuid');
        $setOpt = $input->getOption('set');
        $asJson = (bool)$input->getOption('json');
        $pretty = (bool)$input->getOption('pretty');
        $flags = $pretty ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES : 0;

        return $this->withStorage($input, function ($storage) use ($uuid, $setOpt, $asJson, $flags) {
            try {
                if ($setOpt !== null) {
                    $ttl = (int)$setOpt;
                    if ($ttl < 0) {
                        return $this->failure('TTL must be >= 0', 1);
                    }

                    if ($ttl === 0) {
                        // remove expiration
                        $storage->setExpiration($uuid, null);
                    } else {
                        $storage->setLifetime($uuid, $ttl);
                    }

                    if ($asJson) {
                        $result = [
                            'uuid' => $uuid,
                            'expiresAt' => $storage->getExpiration($uuid),
                            'ttl' => $storage->getLifetime($uuid),
                        ];
                        $this->io->writeln(json_encode($result, $flags));
                        return 0;
                    }

                    return $this->success('TTL updated for ' . $uuid);
                }

                // Read current TTL/info
                $expiresAt = $storage->getExpiration($uuid);
                $ttl = $storage->getLifetime($uuid);

                if ($asJson) {
                    $this->io->writeln(json_encode([
                        'uuid' => $uuid,
                        'expiresAt' => $expiresAt,     // unix timestamp or null
                        'ttl' => $ttl,                 // seconds remaining, may be negative if expired, or null if no expiration
                        'expired' => $ttl !== null ? $ttl <= 0 : false,
                    ], $flags));
                    return 0;
                }

                if ($expiresAt === null) {
                    $this->io->writeln('<title>TTL</title> <muted>no expiration</muted>');
                    return 0;
                }

                if ($ttl !== null && $ttl <= 0) {
                    $this->io->writeln(sprintf('<warn>expired</warn> <muted>(expiresAt: %d)</muted>', $expiresAt));
                    return 0;
                }

                $this->io->writeln(sprintf('<title>TTL</title> <ok>%d seconds</ok> <muted>(expiresAt: %d)</muted>', $ttl ?? 0, $expiresAt));
                return 0;
            } catch (\Throwable $e) {
                return $this->failure('TTL operation failed: ' . $e->getMessage(), 2);
            }
        });
    }
}