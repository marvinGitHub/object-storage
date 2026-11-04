<?php

namespace melia\ObjectStorage\Util\Maintenance;

use FilesystemIterator;
use melia\ObjectStorage\File\Directory\Registry;
use melia\ObjectStorage\Locking\LockAdapterInterface;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Storage\StorageAwareTrait;
use melia\ObjectStorage\UUID\Helper;
use melia\ObjectStorage\Util\UuidTrie;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * A maintenance task run step by step, so its progress can be shown and it can be spread over several requests or
 * continued after an interruption, also by another process.
 *
 * Its state (a TaskState) and its snapshots (UuidTries of the UUIDs still to process) are objects in an internal
 * object storage in <storage dir>/maintenance/internal, one record each, under UUIDs derived from the name of the
 * task. Steps of all processes run one after the other, under a lock of the internal storage.
 */
abstract class SteppedMaintenance
{
    use StorageAwareTrait;

    public const string DIRECTORY = 'maintenance';
    public const string INTERNAL_DIRECTORY = 'internal';

    private ?ObjectStorage $internal = null;

    /**
     * The name of the task, e.g. "rebuild-stubs".
     */
    abstract protected function getName(): string;

    /**
     * Whether the task is running (started and not complete).
     */
    public function isRunning(): bool
    {
        return $this->internalExists() && $this->getInternalStorage()->exists($this->uuidFor('state'));
    }

    /**
     * The internal storage of the maintenance tasks; created on first use.
     */
    public function getInternalStorage(): ObjectStorage
    {
        if (null === $this->internal) {
            $internal = new ObjectStorage($this->internalDirectory(), logger: $this->getObjectStorage()->getLogger());
            // other processes continue the task: always read the stored state
            $internal->setCache(null);
            $this->internal = $internal;
        }

        return $this->internal;
    }

    protected function getObjectStorage(): ObjectStorage
    {
        $storage = $this->getStorage();
        if (!$storage instanceof ObjectStorage) {
            throw new RuntimeException(static::class . ' needs an ObjectStorage.');
        }
        return $storage;
    }

    /**
     * Runs a callback under the lock of the task, so steps of several requests and processes don't overlap.
     */
    protected function locked(callable $callback): mixed
    {
        $lockUuid = $this->uuidFor('lock');
        $lockAdapter = $this->getInternalStorage()->getLockAdapter();

        try {
            $lockAdapter->acquireExclusiveLock($lockUuid, LockAdapterInterface::LOCK_TIMEOUT_DEFAULT);
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf('The task %s is locked by another process.', $this->getName()), 0, $e);
        }

        try {
            return $callback();
        } finally {
            if ($lockAdapter->isLockedByThisProcess($lockUuid)) {
                $lockAdapter->releaseLock($lockUuid);
            }
        }
    }

    /**
     * @return array<string, mixed>|null The values of the running task, null without one.
     */
    protected function readState(): ?array
    {
        if (!$this->isRunning()) {
            return null;
        }

        $state = $this->getInternalStorage()->load($this->uuidFor('state'));
        if (!$state instanceof TaskState) {
            throw new RuntimeException(sprintf('Invalid state of the task %s.', $this->getName()));
        }

        return $state->values;
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function writeState(array $values): void
    {
        $this->getInternalStorage()->store(new TaskState($this->getName(), $values), $this->uuidFor('state'));
    }

    /**
     * A snapshot of the task: UUIDs still to process, in ascending order.
     */
    protected function readTrie(string $kind): UuidTrie
    {
        $uuid = $this->uuidFor('trie:' . $kind);
        $internal = $this->getInternalStorage();
        $trie = $internal->exists($uuid) ? $internal->load($uuid) : null;

        return $trie instanceof UuidTrie ? $trie : new UuidTrie();
    }

    protected function writeTrie(string $kind, UuidTrie $trie): void
    {
        $this->getInternalStorage()->store($trie, $this->uuidFor('trie:' . $kind));
    }

    /**
     * Takes the next UUIDs of a snapshot after a cursor (the last UUID processed).
     *
     * @return array{0: string[], 1: bool} The UUIDs, and whether the snapshot ends with them.
     */
    protected function nextUuids(UuidTrie $trie, ?string $cursor, int $limit): array
    {
        // one more than needed tells whether these are the last ones
        $uuids = $trie->first(max(1, $limit) + 1, $cursor);
        $finished = count($uuids) <= max(1, $limit);

        return [array_slice($uuids, 0, max(1, $limit)), $finished];
    }

    /**
     * Removes the state and the snapshots: the task is complete.
     */
    protected function finish(string ...$trieKinds): void
    {
        $internal = $this->getInternalStorage();
        foreach (['state', ...array_map(static fn(string $kind): string => 'trie:' . $kind, $trieKinds)] as $name) {
            $uuid = $this->uuidFor($name);
            if ($internal->exists($uuid)) {
                $internal->delete($uuid);
            }
        }
    }

    /**
     * Removes empty directories below a directory, deepest first; never the directory itself.
     */
    protected static function removeEmptyDirectories(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $info) {
            if ($info->isDir()) {
                @rmdir($info->getPathname()); // fails for directories that aren't empty
            }
        }

        // removed directories must not count as existing anymore; the directory itself still exists
        Registry::getInstance()->markTreeAsNotVerified($directory);
        Registry::getInstance()->markAsVerified(rtrim($directory, '/\\'));
    }

    /**
     * The UUID of an object of the task in the internal storage, derived from the name of the task.
     */
    private function uuidFor(string $name): string
    {
        return Helper::createFixedUuidV4('melia/object-storage:maintenance:' . $this->getName() . ':' . $name);
    }

    private function internalDirectory(): string
    {
        return $this->getObjectStorage()->getStorageDir() . DIRECTORY_SEPARATOR . self::DIRECTORY . DIRECTORY_SEPARATOR . self::INTERNAL_DIRECTORY;
    }

    /**
     * Nothing started yet: the internal storage isn't created just by looking.
     */
    private function internalExists(): bool
    {
        return null !== $this->internal || is_dir($this->internalDirectory());
    }
}
