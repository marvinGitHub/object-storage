<?php

namespace melia\ObjectStorage\Locking\Backends;

use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\Exception;
use melia\ObjectStorage\Exception\LockException;
use melia\ObjectStorage\Locking\LockAdapterInterface;
use melia\ObjectStorage\Logger\LoggerAwareTrait;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Throwable;

/**
 * Lock adapter based on symfony/lock, so any of its stores can be used, e.g.
 * - FlockStore: local file locks (lock files are kept on purpose, deleting them is not race-free)
 * - RedisStore, MemcachedStore, PdoStore, DoctrineDbalStore: no lock files, works across hosts
 * - SemaphoreStore: no lock files, Linux only (ext-sysvsem)
 *
 * Stores without shared lock support (e.g. MemcachedStore, PdoStore) fall back to exclusive locks for reading.
 */
class SymfonyLockAdapter extends LockAdapterAbstract
{
    use LoggerAwareTrait;

    /**
     * Default lifetime of a lock in seconds for expiring stores (e.g. Redis), so locks of crashed
     * processes are freed eventually. Stores like FlockStore ignore it.
     */
    public const float TTL_DEFAULT = 300.0;

    private LockFactory $lockFactory;

    /**
     * An array to store currently active locks.
     *
     * @var array<string, array{lock: LockInterface, exclusive: bool}>
     */
    private array $activeLocks = [];

    /**
     * @param PersistingStoreInterface $store The symfony/lock store to keep the locks in.
     * @param float|null $ttl The lifetime of a lock in seconds for expiring stores, null for no expiration.
     * @param string $prefix A prefix for the lock resources, to separate storages sharing one store.
     */
    public function __construct(
        PersistingStoreInterface $store,
        private ?float           $ttl = self::TTL_DEFAULT,
        private string           $prefix = 'object-storage.'
    )
    {
        $this->lockFactory = new LockFactory($store);
    }

    /**
     * Creates an adapter with local file locks, the default of the object storage.
     *
     * @param string $lockDir The directory for the lock files, created if it doesn't exist.
     * @throws LockException If the lock directory cannot be created.
     */
    public static function createFileLockAdapter(string $lockDir): self
    {
        // another process may create the directory at the same time: check again instead of warning
        if (!is_dir($lockDir) && !@mkdir($lockDir, 0777, true) && !is_dir($lockDir)) {
            throw new LockException('Unable to create lock directory: ' . $lockDir);
        }

        return new self(new FlockStore($lockDir));
    }

    /**
     * @throws LockException
     */
    public function acquireSharedLock(string $uuid, int|float $timeout = LockAdapterInterface::LOCK_TIMEOUT_DEFAULT): void
    {
        /* any lock held by this process (shared or exclusive) already grants read access */
        if ($this->isLockedByThisProcess($uuid)) {
            return;
        }

        $lock = $this->createLock($uuid);
        $this->acquire($uuid, $lock, false, $timeout);

        $this->activeLocks[$uuid] = ['lock' => $lock, 'exclusive' => false];
        $this->getEventDispatcher()?->dispatch(Events::SHARED_LOCK_ACQUIRED, fn() => new Context($uuid));
    }

    /**
     * @throws LockException
     */
    public function acquireExclusiveLock(string $uuid, int|float $timeout = LockAdapterInterface::LOCK_TIMEOUT_DEFAULT): void
    {
        if ($this->hasActiveExclusiveLock($uuid)) {
            return;
        }

        /* an own shared lock is promoted, a second lock on the same resource would wait for the own one */
        $lock = $this->activeLocks[$uuid]['lock'] ?? $this->createLock($uuid);

        try {
            $this->acquire($uuid, $lock, true, $timeout);
        } catch (LockException $e) {
            // a failed promotion may cost the shared lock as well (e.g. FlockStore closes the handle)
            if (isset($this->activeLocks[$uuid]) && false === $lock->isAcquired()) {
                unset($this->activeLocks[$uuid]);
            }
            throw $e;
        }

        $this->activeLocks[$uuid] = ['lock' => $lock, 'exclusive' => true];
        $this->getEventDispatcher()?->dispatch(Events::EXCLUSIVE_LOCK_ACQUIRED, fn() => new Context($uuid));
    }

    /**
     * Polls for the lock with exponential backoff (1ms -> 50ms cap) until the timeout elapses.
     *
     * @throws LockException
     */
    private function acquire(string $uuid, LockInterface $lock, bool $exclusive, int|float $timeout): void
    {
        // safe mode only prevents modifications: exclusive locks are taken for writing, shared locks for reading,
        // so reads keep working (and skip the safe mode check, which costs a file lookup per load)
        if ($exclusive && $this->getStateHandler()?->safeModeEnabled()) {
            throw new LockException('Safe mode is enabled. Object cannot be locked for writing.');
        }

        $startTime = microtime(true);
        $retryDelay = 1000;
        $shared = !$exclusive && $lock instanceof SharedLockInterface;

        try {
            while (!($shared ? $lock->acquireRead() : $lock->acquire())) {
                if (microtime(true) - $startTime > $timeout) {
                    throw new LockException(sprintf('Timeout while waiting for lock: %s (%s)', $uuid, $exclusive ? 'exclusive' : 'shared'));
                }
                usleep($retryDelay);
                $retryDelay = min($retryDelay * 2, 50000);
            }
        } catch (LockException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new LockException(sprintf('Unable to acquire lock: %s', $uuid), 0, $e);
        }
    }

    private function createLock(string $uuid): LockInterface
    {
        return $this->lockFactory->createLock($this->prefix . $uuid, $this->ttl);
    }

    /**
     * Checks if the lock is held by a process other than the current one by probing whether
     * an exclusive lock could be acquired right now.
     */
    public function isLockedByOtherProcess(?string $uuid): bool
    {
        if (null === $uuid || $this->isLockedByThisProcess($uuid)) {
            return false;
        }

        try {
            $probe = $this->createLock($uuid);
            if (false === $probe->acquire()) {
                return true;
            }
            $probe->release();
        } catch (Throwable $e) {
            $this->getLogger()?->log($e);
        }

        return false;
    }

    public function isLockedByThisProcess(?string $uuid): bool
    {
        if (null === $uuid) {
            return false;
        }
        return isset($this->activeLocks[$uuid]);
    }

    public function releaseActiveLocks(): void
    {
        foreach ($this->getActiveLocks() as $uuid) {
            try {
                $this->releaseLock($uuid);
            } catch (Throwable $e) {
                $this->getLogger()?->log(new LockException(sprintf('Error while unlocking object %s', $uuid), Exception::CODE_FAILURE_OBJECT_UNLOCK, $e));
            }
        }
    }

    public function getActiveLocks(): array
    {
        return array_keys($this->activeLocks);
    }

    /**
     * @throws LockException If no active lock is found for the given UUID or it could not be released.
     */
    public function releaseLock(string $uuid): void
    {
        $lock = $this->activeLocks[$uuid]['lock'] ?? null;

        if (null === $lock) {
            throw new LockException('No active lock found for uuid: ' . $uuid);
        }

        // forget the lock in any case: a failed release must not leave a lock behind that seems to be held
        unset($this->activeLocks[$uuid]);

        try {
            $lock->release();
        } catch (Throwable $e) {
            throw new LockException('Unable to release lock: ' . $uuid, 0, $e);
        }

        $this->getEventDispatcher()?->dispatch(Events::LOCK_RELEASED, fn() => new Context($uuid));
    }

    public function hasActiveSharedLock(string $uuid): bool
    {
        return isset($this->activeLocks[$uuid]) && false === $this->activeLocks[$uuid]['exclusive'];
    }

    public function hasActiveExclusiveLock(string $uuid): bool
    {
        return isset($this->activeLocks[$uuid]) && $this->activeLocks[$uuid]['exclusive'];
    }
}
