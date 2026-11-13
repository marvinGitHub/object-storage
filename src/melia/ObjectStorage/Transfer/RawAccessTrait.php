<?php

namespace melia\ObjectStorage\Transfer;

use melia\ObjectStorage\Event\Context\ClassnameChangeContext;
use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\ObjectAlreadyExistsException;
use melia\ObjectStorage\Exception\ObjectLoadingFailureException;
use melia\ObjectStorage\Exception\ObjectNotFoundException;
use melia\ObjectStorage\Exception\ObjectSavingFailureException;
use Throwable;

/**
 * Reading and writing objects as stored, without loading them: data and metadata unchanged, e.g. to copy them into
 * another storage (ObjectTransfer).
 *
 * Part of ObjectStorage, kept apart for readability: it uses the internals of the storage (reading and verifying
 * the files as loading does, writing them as storing does, locks, caches, stubs and events), so the rules of the
 * storage apply to both ways in the same place.
 *
 * @mixin \melia\ObjectStorage\ObjectStorage
 */
trait RawAccessTrait
{
    /**
     * Reads an object as stored, without loading it: its data and metadata, unchanged, e.g. to write it into
     * another storage with writeRaw(). The checksum is verified, so damaged data isn't passed on.
     *
     * @param string $uuid The unique identifier of the object.
     * @return RawObject The data, the metadata and the UUIDs of the referenced objects.
     * @throws ObjectNotFoundException If there is no such object.
     * @throws ObjectLoadingFailureException If it can't be read, or its data doesn't match its checksum. It is read
     *         as when loading (readObjectFiles()): an interrupted write is completed first, with checksum validation
     *         disabled in the strategy the data is passed on unchecked. Unlike loading, a mismatch doesn't enable
     *         safe mode.
     */
    public function readRaw(string $uuid): RawObject
    {
        $lockAdapter = $this->getLockAdapter();
        $lockedHere = false;
        if (null !== $lockAdapter && !$lockAdapter->isLockedByThisProcess($uuid)) {
            $lockAdapter->acquireSharedLock($uuid);
            $lockedHere = true;
        }

        try {
            // the same reading as loading: interrupted writes completed, the checksum verified; a damaged object
            // is reported without enabling safe mode, so copying other objects goes on
            $stored = $this->readObjectFiles($uuid, safeModeOnCorruption: false);
            if (null === $stored) {
                throw new ObjectNotFoundException(sprintf('Object with uuid %s not found', $uuid));
            }
            [$data, $metadata] = $stored;

            // the data is verified by its checksum: looked into for references only
            $references = [];
            $decoded = $this->getJsonFileReader(safeModeOnCorruption: false)->tryDecode($data);
            if (null !== $decoded) {
                foreach (static::findReferences($decoded, $metadata->getReservedReferenceName()) as [$reference]) {
                    if ($reference !== $uuid) {
                        $references[$reference] = true;
                    }
                }
            }

            // the file names the object (loadMetadata() corrects metadata naming none or another one)
            $metadata = clone $metadata;
            $metadata->setUUID($uuid);

            return new RawObject($uuid, $data, $metadata, array_keys($references));
        } catch (ObjectNotFoundException $e) {
            throw $e;
        } catch (Throwable $e) {
            // with the cause: the message is often all that is shown, e.g. in the errors of a search index
            throw new ObjectLoadingFailureException(message: sprintf('Object with uuid %s could not be read: %s', $uuid, $e->getMessage()), previous: $e);
        } finally {
            if ($lockedHere) {
                $lockAdapter->releaseLock($uuid);
            }
        }
    }

    /**
     * Writes an object as read by readRaw() of another storage: data and metadata unchanged, so the UUID, the
     * revision, the expiration and the checksum are kept. The files are written as storing does
     * (writeObjectFiles()), the stub and the class are registered, and the events of storing (METADATA_SAVED,
     * AFTER_STORE) are dispatched, e.g. for search indexes.
     *
     * @param RawObject $object The object to write.
     * @param bool $overwrite Whether to replace an object of the same UUID.
     * @throws ObjectAlreadyExistsException If an object of the UUID exists and $overwrite is false.
     * @throws ObjectSavingFailureException If it can't be written, e.g. in safe mode.
     */
    public function writeRaw(RawObject $object, bool $overwrite = false): void
    {
        $uuid = $object->uuid;
        $metadata = clone $object->metadata;
        $eventDispatcher = $this->getEventDispatcher();
        $lockAdapter = $this->getLockAdapter();
        $lockedHere = false;

        try {
            if ($this->getStateHandler()?->safeModeEnabled()) {
                throw new ObjectSavingFailureException('Safe mode is enabled. Object cannot be stored.');
            }
            // the metadata is written under the UUID of the object, so it names that one
            $metadata->setUUID($uuid);

            if (null !== $lockAdapter && !$lockAdapter->hasActiveExclusiveLock($uuid)) {
                $lockAdapter->acquireExclusiveLock($uuid);
                $lockedHere = true;
            }

            $previous = $this->exists($uuid) ? $this->reloadMetadata($uuid) : null;
            if ($this->exists($uuid) && !$overwrite) {
                throw new ObjectAlreadyExistsException(sprintf('Object with uuid %s exists already', $uuid));
            }

            $this->removeFromCache($uuid);

            // the constraints of this storage apply to copies as well, e.g. unique values
            $finalizeConstraints = $this->checkConstraints($uuid, $metadata->getClassName(), $object->data, $metadata->getReservedReferenceName());
            $written = false;
            try {
                $this->writeObjectFiles($uuid, $object->data, $metadata);
                $written = true;
            } finally {
                $finalizeConstraints($written);
            }
            $this->cacheMetadata($metadata);

            $className = $metadata->getClassName();
            if (null !== $previous && $previous->getClassName() !== $className) {
                $this->deleteStub($previous->getClassName(), $uuid);
                $previousClassName = $previous->getClassName();
                $eventDispatcher?->dispatch(Events::CLASSNAME_CHANGED, static fn() => new ClassnameChangeContext($uuid, $previousClassName, $className));
            }
            $this->createStub($className, $uuid);

            $eventDispatcher?->dispatch(Events::METADATA_SAVED, static fn() => new Context($uuid));
            $eventDispatcher?->dispatch(Events::AFTER_STORE, static fn() => new Context($uuid));
        } catch (ObjectSavingFailureException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ObjectSavingFailureException(message: sprintf('Unable to save object with uuid: %s', $uuid), previous: $e);
        } finally {
            // a transaction may have retained the lock while the object was written
            if ($lockedHere && !$this->isLockRetained($uuid)) {
                $lockAdapter->releaseLock($uuid);
            }
        }
    }
}
