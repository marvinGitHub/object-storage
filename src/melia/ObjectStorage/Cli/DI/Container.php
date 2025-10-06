<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\DI;

use melia\ObjectStorage\Cache\InMemoryCache;
use melia\ObjectStorage\Event\Dispatcher;
use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\Locking\Backends\FileSystem as FileSystemLockingBackend;
use melia\ObjectStorage\Logger\LoggerInterface;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\State\StateHandler;
use RuntimeException;
use Throwable;

final class Container
{
    /**
     * @throws IOException
     */
    public static function makeStorage(string $dir): ObjectStorage
    {
        // Ensure a directory exists; ObjectStorage may also create, but we prefer explicit handling.
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create storage directory: ' . $dir);
        }

        $logger = new class implements LoggerInterface {
            public function log(Throwable|string $message): void
            {
                // No-op logger for CLI; swap with PSR adapter if needed.
                fwrite(STDERR, (string) $message . PHP_EOL);
                if ($message instanceof Throwable) {
                    fwrite(STDERR, $message->getTraceAsString() . PHP_EOL);
                }
            }
        };

        $eventDispatcher = new Dispatcher();
        $state = new StateHandler($dir);
        $state->setEventDispatcher($eventDispatcher);

        $lock = new FileSystemLockingBackend($dir);
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
}