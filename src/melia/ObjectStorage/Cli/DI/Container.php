<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\DI;

use melia\ObjectStorage\Cache\InMemoryCache;
use melia\ObjectStorage\Configuration\Configuration;
use melia\ObjectStorage\Configuration\Exception\ConfigurationException;
use melia\ObjectStorage\Event\Dispatcher;
use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\Locking\Backends\SymfonyLockAdapter;
use melia\ObjectStorage\Logger\LoggerInterface;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\State\StateHandler;
use RuntimeException;
use Throwable;

final class Container
{
    /**
     * Opens a storage for the CLI: with a configuration, its lock backend and caches; without, file locks and a
     * cache in memory.
     *
     * @throws IOException
     * @throws ConfigurationException If the configuration can't be applied, e.g. its lock backend is unreachable.
     */
    public static function makeStorage(string $dir, ?Configuration $configuration = null): ObjectStorage
    {
        // Ensure a directory exists; ObjectStorage may also create, but we prefer explicit handling.
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create storage directory: ' . $dir);
        }

        $logger = self::makeLogger();

        $eventDispatcher = new Dispatcher();
        $state = new StateHandler($dir);
        $state->setEventDispatcher($eventDispatcher);

        if (null !== $configuration) {
            // the lock adapter and the caches are created from the configuration, wired like the default ones
            return new ObjectStorage($dir, $logger, stateHandler: $state, eventDispatcher: $eventDispatcher, configuration: $configuration);
        }

        $lock = SymfonyLockAdapter::createFileLockAdapter($dir . DIRECTORY_SEPARATOR . 'locks');
        $lock->setStateHandler($state);
        $lock->setLogger($logger);
        $lock->setEventDispatcher($eventDispatcher);

        $cache = new InMemoryCache();

        return new ObjectStorage(
            $dir,
            $logger,
            $lock,
            $state,
            $eventDispatcher,
            $cache
        );
    }

    /**
     * Reports errors that don't stop an operation on STDERR.
     */
    public static function makeLogger(): LoggerInterface
    {
        return new class implements LoggerInterface {
            public function log(Throwable|string $message): void
            {
                fwrite(STDERR, (string)$message . PHP_EOL);
                if ($message instanceof Throwable) {
                    fwrite(STDERR, $message->getTraceAsString() . PHP_EOL);
                }
            }
        };
    }
}
