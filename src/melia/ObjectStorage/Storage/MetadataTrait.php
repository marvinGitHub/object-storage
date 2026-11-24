<?php

namespace melia\ObjectStorage\Storage;

use melia\ObjectStorage\Exception\MetadataSavingFailureException;
use melia\ObjectStorage\Metadata\Metadata;

/**
 * The metadata of objects, delegated to the component MetadataStore: read through its cache (loadMetadata()) or
 * from the file (reloadMetadata()), written, and what it tells (revision, class).
 *
 * Part of ObjectStorage, kept apart for readability.
 *
 * @mixin \melia\ObjectStorage\ObjectStorage
 */
trait MetadataTrait
{
    /**
     * The metadata of the storage, with its paths, IO adapter, JSON files, metadata cache, event dispatcher and
     * logger as they are now.
     */
    protected function metadataStore(): MetadataStore
    {
        $paths = $this->objectPaths();
        $io = $this->getIOAdapter();
        $json = $this->jsonStore();
        $cache = $this->getMetadataCache();
        $dispatcher = $this->getEventDispatcher();
        $logger = $this->getLogger();

        return $this->component(
            MetadataStore::class,
            [$paths, $io, $json, $cache, $dispatcher, $logger],
            static fn(): MetadataStore => new MetadataStore($paths, $io, $json, $cache, $dispatcher, $logger),
        );
    }

    /**
     * The metadata of an object through its cache, see MetadataStore::load().
     *
     * @param null|string $uuid The unique identifier of the object.
     * @return Metadata|null The metadata, or null if there is none or it can't be read.
     */
    public function loadMetadata(?string $uuid): ?Metadata
    {
        return null === $uuid ? null : $this->metadataStore()->load($uuid);
    }

    /**
     * The metadata of an object from its file, bypassing and refreshing the cache, see MetadataStore::reload().
     *
     * @param string $uuid The unique identifier of the object.
     * @return Metadata|null The metadata on disk, or null if it cannot be loaded.
     */
    protected function reloadMetadata(string $uuid): ?Metadata
    {
        return $this->metadataStore()->reload($uuid);
    }

    /**
     * Writes metadata, recorded in the active transaction first, see MetadataStore::write().
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

        $this->metadataStore()->write($metadata);
    }

    /**
     * Puts metadata into the metadata cache; a failing cache is logged, not thrown.
     *
     * @param Metadata $metadata The metadata to cache.
     * @return void
     */
    protected function cacheMetadata(Metadata $metadata): void
    {
        $this->metadataStore()->cache($metadata);
    }

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
