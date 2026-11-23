<?php

namespace melia\ObjectStorage\Storage;

use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\Exception;
use melia\ObjectStorage\Exception\MetadataNotFoundException;
use melia\ObjectStorage\Exception\MetadataSavingFailureException;
use melia\ObjectStorage\Metadata\Metadata;
use Throwable;

/**
 * The metadata of objects: read through its cache (loadMetadata()) or from the file (reloadMetadata()), written,
 * and what it tells (revision, class, times).
 *
 * Part of ObjectStorage, kept apart for readability.
 *
 * @mixin \melia\ObjectStorage\ObjectStorage
 */
trait MetadataTrait
{
    /**
     * Loads metadata associated with a given UUID by reading from a JSON file and validating it.
     * If an error occurs during the process, it is logged, and null is returned.
     *
     * @param null|string $uuid The unique identifier for the metadata to be loaded.
     * @return Metadata|null The loaded and validated metadata object, or null if loading fails.
     */
    public function loadMetadata(?string $uuid): ?Metadata
    {
        if (null === $uuid) {
            return null;
        }

        try {
            $cached = $this->getMetadataCache()?->get($uuid);
            if ($cached instanceof Metadata) {
                return $cached;
            }
        } catch (Throwable $e) {
            $this->getLogger()?->log($e);
        }

        try {
            $filePathMetadata = $this->getFilePathMetadata($uuid);

            // no metadata stored under this UUID: nothing to load, which is not a failure worth reporting
            if (false === $this->getIOAdapter()->isFile($filePathMetadata)) {
                return null;
            }

            $metadata = $this->getJsonFileReader()->readJson($filePathMetadata);
            if (null === $metadata) {
                throw new MetadataNotFoundException('Unable to load metadata for uuid: ' . $uuid);
            }

            $metadata = Metadata::createFromArray($metadata);
            // the file names the object: metadata of older versions doesn't name its UUID, and copies of metadata
            // (e.g. duplicates made by older versions of the viewer) name the original, whose metadata would be
            // overwritten when the metadata of the copy is written
            $named = $metadata->getUUID();
            if (null === $named || '' === $named || strtolower($named) !== strtolower($uuid)) {
                if (null !== $named && '' !== $named) {
                    $this->getLogger()?->log(new Exception(sprintf('The metadata of %s named %s; corrected, written with the next change', $uuid, $named)));
                }
                $metadata->setUUID($uuid);
            }
            $this->completeTimestamps($metadata, $filePathMetadata);
            $this->getMetadataCache()?->set($uuid, $metadata);
            return $metadata;
        } catch (Throwable $e) {
            $this->getEventDispatcher()?->dispatch(Events::METADATA_LOADING_FAILURE, static fn() => new Context($uuid));
            $this->getLogger()?->log($e);
        }
        return null;
    }

    /**
     * Loads the metadata from disk, bypassing and refreshing the metadata cache.
     *
     * @param string $uuid The unique identifier of the object.
     * @return Metadata|null The metadata on disk, or null if it cannot be loaded.
     */
    protected function reloadMetadata(string $uuid): ?Metadata
    {
        try {
            $this->getMetadataCache()?->delete($uuid);
        } catch (Throwable $e) {
            $this->getLogger()?->log($e);
        }

        return $this->loadMetadata($uuid);
    }

    /**
     * Saves the provided metadata to a file by serializing it to JSON and writing it atomically.
     *
     * @param Metadata $metadata The metadata to be serialized and saved.
     * @return void
     * @throws MetadataSavingFailureException
     */
    protected function writeMetadata(Metadata $metadata): void
    {
        $uuid = $metadata->getUUID();
        if (null !== $uuid) {
            $this->getActiveTransaction()?->track($uuid);
        }

        try {
            $this->getJsonFileWriter()->write($this->getFilePathMetadata($metadata->getUUID()), $metadata);
            $this->getEventDispatcher()?->dispatch(Events::METADATA_SAVED, static fn() => new Context($metadata->getUUID()));
        } catch (Throwable $e) {
            $this->getEventDispatcher()?->dispatch(Events::METADATA_WRITE_FAILED, static fn() => new Context($metadata->getUUID()));
            $this->getLogger()?->log($e);
            throw new MetadataSavingFailureException(message: 'Unable to save metadata for uuid: ' . $metadata->getUUID(), previous: $e);
        }

        $this->cacheMetadata($metadata);
    }

    /**
     * Puts metadata into the metadata cache; a failing cache is logged, not thrown.
     *
     * @param Metadata $metadata The metadata to cache.
     * @return void
     */
    protected function cacheMetadata(Metadata $metadata): void
    {
        try {
            $this->getMetadataCache()?->set($metadata->getUUID(), $metadata);
        } catch (Throwable $e) {
            $this->getLogger()?->log($e);
        }
    }

    /**
     * Retrieves the lifetime of the metadata associated with the specified UUID.
     *
     * @param string $uuid The unique identifier used to load the metadata.
     * @return float|null The lifetime of the metadata in seconds, or null if no metadata is found.
     */
    /**
     * Retrieves the revision of a stored object: incremented with every write of its data.
     *
     * @param string $uuid The unique identifier of the object.
     * @return int|null The revision (0 for objects stored before revisions existed), or null if nothing is stored.
     */
    public function getRevision(string $uuid): ?int
    {
        return $this->loadMetadata($uuid)?->getRevision();
    }

    /**
     * Get a classname for a certain object
     *
     * @param string $uuid
     * @return string|null
     */
    public function getClassName(string $uuid): ?string
    {
        return $this->loadMetadata($uuid)?->getClassName();
    }
}
