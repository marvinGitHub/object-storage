<?php

namespace melia\ObjectStorage\Storage;

use Generator;
use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\MetataDeletionFailureException;
use melia\ObjectStorage\Exception\ObjectDeletionFailureException;
use melia\ObjectStorage\Exception\ObjectNotFoundException;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\Strategy\Policy\ChildDeletion;
use melia\ObjectStorage\Strategy\StrategyInterface;
use melia\ObjectStorage\UUID\Validator;
use Throwable;

/**
 * Deleting objects: delete() → deleteObject() (per object, following the child deletion policy).
 *
 * Part of ObjectStorage, kept apart for readability.
 *
 * @mixin \melia\ObjectStorage\ObjectStorage
 */
trait DeletingTrait
{
    /**
     * Deletes an object based on its UUID.
     *
     * @param string $uuid The unique identifier of the object to be deleted.
     * @param bool $applyChildDeletionPolicy False to delete only the object itself, whatever the child deletion
     *        policy says, e.g. after moving it to another storage.
     * @return void
     * @throws ObjectDeletionFailureException Thrown when the object could not be deleted.
     */
    public function delete(string $uuid, bool $applyChildDeletionPolicy = true): void
    {
        // referenced objects are deleted after the object itself, each under its own lock, so at most one lock is
        // held at once; a crash in between leaves unreferenced objects behind, never references to deleted ones
        $pending = $this->deleteObject($uuid);
        if (!$applyChildDeletionPolicy) {
            return;
        }
        $visited = [$uuid => true];
        $failures = [];

        while (null !== ($childUuid = array_shift($pending))) {
            if (isset($visited[$childUuid])) {
                continue;
            }
            $visited[$childUuid] = true;

            try {
                array_push($pending, ...$this->deleteObject($childUuid));
            } catch (ObjectDeletionFailureException $e) {
                // deleted by another process in the meantime
                if ($e->getPrevious() instanceof ObjectNotFoundException) {
                    continue;
                }
                $this->getLogger()?->log($e);
                $failures[] = $e;
            }
        }

        if ([] !== $failures) {
            throw new ObjectDeletionFailureException(
                message: sprintf('Object with uuid %s was deleted, but %u referenced object(s) could not be deleted', $uuid, count($failures)),
                previous: $failures[0]
            );
        }
    }

    /**
     * Deletes an object if it has expired, checked under its exclusive lock with its metadata as on disk: an object
     * whose lifetime another process extended after it was found expired is kept.
     *
     * @param string $uuid The unique identifier of the object.
     * @return bool True if the object was expired and has been deleted, false if it isn't expired (or doesn't exist).
     * @throws ObjectDeletionFailureException If the object can't be deleted.
     */
    public function deleteIfExpired(string $uuid): bool
    {
        $lockAdapter = $this->getLockAdapter();
        $lockedHere = false;

        try {
            if (null !== $lockAdapter && !$lockAdapter->hasActiveExclusiveLock($uuid)) {
                $lockAdapter->acquireExclusiveLock($uuid);
                $lockedHere = true;
            }

            $this->reloadMetadata($uuid);
            if (!$this->expired($uuid)) {
                return false;
            }

            $this->delete($uuid);
            return true;
        } catch (ObjectDeletionFailureException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ObjectDeletionFailureException(message: 'Object with uuid ' . $uuid . ' could not be deleted', previous: $e);
        } finally {
            if ($lockedHere && !$this->isLockRetained($uuid) && $lockAdapter->isLockedByThisProcess($uuid)) {
                $lockAdapter->releaseLock($uuid);
            }
        }
    }

    /**
     * Deletes a single object and determines which of the objects it references are deleted along with it,
     * according to the child deletion policy of the strategy.
     *
     * @param string $uuid The unique identifier of the object to delete.
     * @return string[] The UUIDs of the referenced objects to delete next.
     * @throws ObjectDeletionFailureException
     */
    protected function deleteObject(string $uuid): array
    {
        $eventDispatcher = $this->getEventDispatcher();
        $lockAdapter = $this->getLockAdapter();
        $adapter = $this->getIOAdapter();

        $eventDispatcher?->dispatch(Events::BEFORE_DELETE, static fn() => new Context($uuid));

        try {
            if ($this->getStateHandler()?->safeModeEnabled()) {
                throw new ObjectDeletionFailureException('Safe mode is enabled. Object cannot be deleted.');
            }

            $lockAdapter?->acquireExclusiveLock($uuid);

            $this->removeFromCache($uuid);

            if (!$this->exists($uuid)) {
                throw new ObjectNotFoundException(sprintf('Object with uuid %s not found', $uuid));
            }

            // complete an interrupted write first, so no leftovers of it remain (or are committed) after deleting
            if ($this->recoverInterruptedWrite($uuid)) {
                $this->reloadMetadata($uuid);
            }

            // under the lock, before anything is removed: also objects deleted along by the child deletion policy
            $this->getActiveTransaction()?->track($uuid);

            $className = $this->getClassName($uuid);
            $filePath = $this->getFilePathData($uuid);

            // read before the data file is gone; under the lock, so the references are the ones being deleted
            $children = $this->findChildrenToDelete($uuid, (string)$className, $filePath);

            if (!$adapter->unlink($filePath)) {
                throw new ObjectDeletionFailureException('Object with uuid ' . $uuid . ' could not be deleted');
            }

            $filePathMetadata = $this->getFilePathMetadata($uuid);
            if ($adapter->isFile($filePathMetadata) && !$adapter->unlink($filePathMetadata)) {
                throw new MetataDeletionFailureException('Metadata for uuid ' . $uuid . ' could not be deleted');
            }

            $this->getMetadataCache()?->delete($uuid);

            $this->deleteStub($className, $uuid);

            $eventDispatcher?->dispatch(Events::AFTER_DELETE, static fn() => new Context($uuid));

            return $children;
        } catch (Throwable $e) {
            $eventDispatcher?->dispatch(Events::OBJECT_DELETION_FAILURE, static fn() => new Context($uuid));
            throw new ObjectDeletionFailureException(message: 'Object with uuid ' . $uuid . ' could not be deleted', previous: $e);
        } finally {
            if (!$this->isLockRetained($uuid) && $lockAdapter?->isLockedByThisProcess($uuid)) {
                $lockAdapter?->releaseLock($uuid);
            }
        }
    }

    /**
     * Determines the referenced objects to delete along with an object, according to the child deletion policy.
     * Data that can't be read or decoded yields no referenced objects, so a corrupted object can still be deleted.
     *
     * @param string $uuid The unique identifier of the object being deleted.
     * @param string $className The class name of the object being deleted.
     * @param string $filePath The path of its data file.
     * @return string[] The UUIDs of the referenced objects to delete.
     */
    protected function findChildrenToDelete(string $uuid, string $className, string $filePath): array
    {
        $strategy = $this->getStrategy();
        $policy = $strategy?->getPolicyChildDeletion() ?? StrategyInterface::DEFAULT_POLICY_CHILD_DELETION;

        if (ChildDeletion::NEVER === $policy) {
            return [];
        }

        $metadata = $this->loadMetadata($uuid);
        $reader = $this->getJsonFileReader();
        $raw = $reader->read($filePath);
        if (null === $metadata || null === $raw) {
            return [];
        }

        // the object is deleted anyway: invalid data means no references to follow, not a damaged storage
        $data = $reader->tryDecode($raw);
        if (null === $data) {
            return [];
        }

        $children = [];
        foreach (static::findReferences($data, $metadata->getReservedReferenceName()) as [$childUuid, $path]) {
            if ($childUuid === $uuid || isset($children[$childUuid])) {
                continue;
            }

            if (ChildDeletion::CALLBACK === $policy) {
                $childClassName = $this->getClassName($childUuid);
                if (null === $childClassName) {
                    continue; // not stored (anymore)
                }

                try {
                    $delete = $strategy->shouldDeleteChild($uuid, $className, $childUuid, $childClassName, $path);
                } catch (Throwable $e) {
                    $this->getLogger()?->log($e);
                    $delete = false; // fail-closed: keep the referenced object
                }

                if (true !== $delete) {
                    continue;
                }
            }

            $children[$childUuid] = true;
        }

        return array_keys($children);
    }

    /**
     * Finds the references to other objects in the stored data of an object.
     *
     * @param array $data The decoded data of the object.
     * @param string $reserved The reserved reference name the data was written with.
     * @param array $path The path of $data within the data of the object.
     * @return Generator<array{0: string, 1: array}> Pairs of the referenced UUID and the path of the reference.
     */
    protected static function findReferences(array $data, string $reserved, array $path = []): Generator
    {
        foreach ($data as $key => $value) {
            if (false === is_array($value)) {
                continue;
            }

            $childPath = [...$path, $key];

            if (isset($value[$reserved]) && is_string($value[$reserved]) && Validator::validate($value[$reserved])) {
                yield [$value[$reserved], $childPath];
            } else {
                yield from static::findReferences($value, $reserved, $childPath);
            }
        }
    }
}
