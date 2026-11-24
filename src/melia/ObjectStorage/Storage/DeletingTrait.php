<?php

namespace melia\ObjectStorage\Storage;

use melia\ObjectStorage\Exception\ObjectDeletionFailureException;
use Throwable;

/**
 * Deleting objects, delegated to the component ObjectDeleter (per object, following the child deletion policy).
 *
 * Part of ObjectStorage, kept apart for readability.
 *
 * @mixin \melia\ObjectStorage\ObjectStorage
 */
trait DeletingTrait
{
    /**
     * The deleting of objects, with the components and the strategy, state handler, event dispatcher and logger of
     * the storage as they are now.
     */
    protected function objectDeleter(): ObjectDeleter
    {
        $paths = $this->objectPaths();
        $io = $this->getIOAdapter();
        $files = $this->getFiles();
        $objectFiles = $this->objectFiles();
        $metadata = $this->metadataStore();
        $stubs = $this->stubIndex();
        $cache = $this->objectCache();
        $locks = $this->objectLocks();
        $strategy = $this->getStrategy();
        $stateHandler = $this->getStateHandler();
        $dispatcher = $this->getEventDispatcher();
        $logger = $this->getLogger();

        return $this->component(
            ObjectDeleter::class,
            [$paths, $io, $files, $objectFiles, $metadata, $stubs, $cache, $locks, $strategy, $stateHandler, $dispatcher, $logger],
            static fn(): ObjectDeleter => new ObjectDeleter($paths, $io, $files, $objectFiles, $metadata, $stubs, $cache, $locks, $strategy, $stateHandler, $dispatcher, $logger),
        );
    }

    /**
     * Deletes an object based on its UUID, see ObjectDeleter::delete(); the active transaction records every object
     * before it is deleted.
     *
     * @param string $uuid The unique identifier of the object to be deleted.
     * @param bool $applyChildDeletionPolicy False to delete only the object itself, whatever the child deletion
     *        policy says, e.g. after moving it to another storage.
     * @return void
     * @throws ObjectDeletionFailureException Thrown when the object could not be deleted.
     */
    public function delete(string $uuid, bool $applyChildDeletionPolicy = true): void
    {
        $this->objectDeleter()->delete($uuid, $applyChildDeletionPolicy, $this->getActiveTransaction());
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
        try {
            return $this->objectLocks()->exclusively($uuid, function () use ($uuid): bool {
                $this->reloadMetadata($uuid);
                if (!$this->expired($uuid)) {
                    return false;
                }

                $this->delete($uuid);
                return true;
            });
        } catch (ObjectDeletionFailureException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ObjectDeleter::failure($uuid, $e);
        }
    }
}
