<?php

namespace Tests\melia\ObjectStorage\Concurrency;

use FilesystemIterator;
use melia\ObjectStorage\File\Writer;
use melia\ObjectStorage\ObjectStorage;
use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\melia\ObjectStorage\TestCase;

/**
 * Runs several processes on the same storage at once (see worker.php), as in production with
 * several PHP workers. Exclude these tests with --exclude-group concurrency.
 */
#[Group('concurrency')]
class ConcurrencyTest extends TestCase
{
    private const int WORKERS = 4;

    public function testConcurrentWritesOfASharedChildDontFail(): void
    {
        $child = new SharedChild('setup');
        $this->storage->store($child);
        $uuid = $child->getUUID();

        $errors = $this->runWorkers('shared-child', [$uuid, 40]);

        $this->assertSame([], $errors);
        $this->assertStorageIsConsistent();

        $loaded = $this->createStorageFor($this->storage)->load($uuid);
        $this->assertInstanceOf(SharedChild::class, $loaded);
        $this->assertSame(40, $loaded->round);
        // the data matches the writer: the files of different writers didn't get mixed up
        $this->assertSame(str_repeat($loaded->writer, 100_000 + 40), $loaded->payload);
    }

    public function testExclusiveReadModifyWriteLosesNoUpdates(): void
    {
        $counter = new Counter();
        $this->storage->store($counter);

        $errors = $this->runWorkers('increment', [$counter->getUUID(), 50]);

        $this->assertSame([], $errors);
        $this->assertStorageIsConsistent();
        $this->assertSame(self::WORKERS * 50, $this->createStorageFor($this->storage)->load($counter->getUUID())->value);
    }

    public function testReadsDuringConcurrentWritesSucceed(): void
    {
        $counter = new Counter();
        $this->storage->store($counter);
        $uuid = $counter->getUUID();

        $errors = $this->runWorkers([
            ['increment', [$uuid, 100]],
            ['increment', [$uuid, 100]],
            ['read', [$uuid, 300]],
            ['read', [$uuid, 300]],
        ]);

        $this->assertSame([], $errors);
        $this->assertStorageIsConsistent();
    }

    public function testConcurrentClassRegistrationsAreAllKept(): void
    {
        $errors = $this->runWorkers('register-classes', [25]);

        $this->assertSame([], $errors);

        $registered = $this->createStorageFor($this->storage)->getRegisteredClassnames();
        $missing = [];
        for ($worker = 1; $worker <= self::WORKERS; $worker++) {
            for ($i = 0; $i < 25; $i++) {
                $className = sprintf('Generated_w%d_%d', $worker, $i);
                if (!isset($registered[$className])) {
                    $missing[] = $className;
                }
            }
        }
        $this->assertSame([], $missing, 'class names lost by concurrent registrations');
    }

    /**
     * Starts the workers, lets them begin at the same time and waits for all of them.
     *
     * @param string|array<array{string, array}> $scenario One scenario for all workers, or a scenario and its
     *                                                     arguments per worker.
     * @param array $args The arguments of the scenario, if one is given for all workers.
     * @return string[] The errors reported by the workers, prefixed with the worker name.
     */
    private function runWorkers(string|array $scenario, array $args = []): array
    {
        $jobs = is_string($scenario) ? array_fill(0, self::WORKERS, [$scenario, $args]) : $scenario;
        $dir = $this->storage->getStorageDir();
        $startFile = $dir . DIRECTORY_SEPARATOR . 'start-' . uniqid();

        $processes = [];
        foreach ($jobs as $index => [$name, $jobArgs]) {
            $worker = 'w' . ($index + 1);
            $command = array_merge([PHP_BINARY, __DIR__ . '/worker.php', $name, $dir, $startFile, $worker], array_map('strval', $jobArgs));
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $processes[$worker] = [$process, $pipes];
        }

        touch($startFile);

        $errors = [];
        foreach ($processes as $worker => [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            foreach (preg_split('/\R/', trim($output), -1, PREG_SPLIT_NO_EMPTY) as $line) {
                $errors[] = "$worker: $line";
            }
            if (0 !== $exitCode) {
                $errors[] = "$worker: exit code $exitCode";
            }
        }

        unlink($startFile);

        return $errors;
    }

    /**
     * The storage must not have switched to safe mode, and no write may have been left unfinished.
     */
    private function assertStorageIsConsistent(): void
    {
        $storage = $this->createStorageFor($this->storage);
        $this->assertFalse($storage->getStateHandler()->safeModeEnabled(), 'safe mode was enabled');

        $leftovers = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage->getStorageDir(), FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (str_ends_with($file->getFilename(), Writer::SUFFIX_PENDING) || str_contains($file->getFilename(), Writer::SUFFIX_STAGING . '.')) {
                $leftovers[] = $file->getPathname();
            }
        }
        $this->assertSame([], $leftovers, 'unfinished writes were left behind');
    }

    /**
     * A new storage on the same directory, which knows nothing but the files (like another process).
     */
    private function createStorageFor(ObjectStorage $storage): ObjectStorage
    {
        return new ObjectStorage($storage->getStorageDir());
    }
}
