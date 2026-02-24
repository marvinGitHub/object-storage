<?php

namespace melia\ObjectStorage\Locking\Backends;

use melia\ObjectStorage\Exception\LockException;
use Memcache as Cache;
use melia\ObjectStorage\Locking\LockAdapterInterface;

class Memcache extends LockAdapterAbstract
{
    private Cache $memcache;
    private string $processId;
    private array $activeLocks = [];

    public function __construct(Cache $memcache)
    {
        $this->memcache = $memcache;
        $this->processId = uniqid(gethostname() . '_', true);
    }

    public function acquireSharedLock(string $uuid, float|int $timeout = LockAdapterInterface::LOCK_TIMEOUT_DEFAULT): bool
    {
        $key = $this->getLockKey($uuid);
        $startTime = microtime(true);
        $timeoutSeconds = $timeout;

        while (true) {
            $lockData = $this->memcache->get($key);

            if ($lockData === false) {
                // No lock exists, create shared lock
                $newLockData = [
                    'type' => self::LOCK_TYPE_SHARED,
                    'holders' => [$this->processId => time()],
                ];

                if ($this->memcache->add($key, $newLockData, 0)) {
                    $this->activeLocks[$uuid] = self::LOCK_TYPE_SHARED;
                    return true;
                }
            } elseif ($lockData['type'] === self::LOCK_TYPE_SHARED) {
                // Shared lock exists, add this process
                $lockData['holders'][$this->processId] = time();

                if ($this->memcache->replace($key, $lockData, 0)) {
                    $this->activeLocks[$uuid] = self::LOCK_TYPE_SHARED;
                    return true;
                }
            }

            // Check timeout
            if ((microtime(true) - $startTime) >= $timeoutSeconds) {
                throw new LockException(
                    sprintf('Timeout while waiting for lock: %s (shared)', $uuid)
                );
            }

            usleep(10000); // Wait 10ms before retry
        }
    }

    /**
     * @throws LockException
     */
    public function acquireExclusiveLock(string $uuid, float|int $timeout = LockAdapterInterface::LOCK_TIMEOUT_DEFAULT): bool
    {
        $key = $this->getLockKey($uuid);
        $startTime = microtime(true);
        $timeoutSeconds = $timeout;

        while (true) {
            $lockData = [
                'type' => self::LOCK_TYPE_EXCLUSIVE,
                'holder' => $this->processId,
                'time' => time(),
            ];

            // Try to acquire exclusive lock (only works if key doesn't exist)
            if ($this->memcache->add($key, $lockData, 0)) {
                $this->activeLocks[$uuid] = self::LOCK_TYPE_EXCLUSIVE;
                return true;
            }

            // Check timeout
            if ((microtime(true) - $startTime) >= $timeoutSeconds) {
                throw new LockException(
                    sprintf('Timeout while waiting for lock: %s (exclusive)', $uuid)
                );
            }

            usleep(10000); // Wait 10ms before retry
        }
    }

    public function releaseLock(string $uuid): void
    {
        $key = $this->getLockKey($uuid);
        $lockData = $this->memcache->get($key);

        if ($lockData === false) {
            unset($this->activeLocks[$uuid]);
            return;
        }

        if ($lockData['type'] === self::LOCK_TYPE_EXCLUSIVE) {
            // Release exclusive lock
            if (isset($lockData['holder']) && $lockData['holder'] === $this->processId) {
                $this->memcache->delete($key);
            }
        } elseif ($lockData['type'] === self::LOCK_TYPE_SHARED) {
            // Remove this process from shared lock holders
            if (isset($lockData['holders'][$this->processId])) {
                unset($lockData['holders'][$this->processId]);

                if (empty($lockData['holders'])) {
                    $this->memcache->delete($key);
                } else {
                    $this->memcache->replace($key, $lockData, 0);
                }
            }
        }

        unset($this->activeLocks[$uuid]);
    }

    public function isLockedByOtherProcess(?string $uuid): bool
    {
        if ($uuid === null) {
            return false;
        }

        $key = $this->getLockKey($uuid);
        $lockData = $this->memcache->get($key);

        if ($lockData === false) {
            return false;
        }

        if ($lockData['type'] === self::LOCK_TYPE_EXCLUSIVE) {
            return $lockData['holder'] !== $this->processId;
        }

        // For shared locks, check if there are any other holders
        if ($lockData['type'] === self::LOCK_TYPE_SHARED) {
            return count($lockData['holders']) > 1 || !isset($lockData['holders'][$this->processId]);
        }

        return false;
    }

    public function isLockedByThisProcess(?string $uuid): bool
    {
        if ($uuid === null) {
            return false;
        }

        return isset($this->activeLocks[$uuid]);
    }

    public function releaseActiveLocks(): void
    {
        foreach (array_keys($this->activeLocks) as $uuid) {
            $this->releaseLock($uuid);
        }
    }

    public function getActiveLocks(): array
    {
        return array_keys($this->activeLocks);
    }

    public function hasActiveSharedLock(string $uuid): bool
    {
        return isset($this->activeLocks[$uuid]) &&
            $this->activeLocks[$uuid] === self::LOCK_TYPE_SHARED;
    }

    public function hasActiveExclusiveLock(string $uuid): bool
    {
        return isset($this->activeLocks[$uuid]) &&
            $this->activeLocks[$uuid] === self::LOCK_TYPE_EXCLUSIVE;
    }

    private function getLockKey(string $uuid): string
    {
        return 'lock:' . $uuid;
    }
}