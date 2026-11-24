<?php

namespace melia\ObjectStorage\Storage;

use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\DispatcherInterface;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\Exception;
use melia\ObjectStorage\Exception\MetadataNotFoundException;
use melia\ObjectStorage\Exception\MetadataSavingFailureException;
use melia\ObjectStorage\File\IO\AdapterInterface;
use melia\ObjectStorage\Logger\LoggerInterface;
use melia\ObjectStorage\Metadata\Metadata;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * The metadata of objects: read through its cache or from its file, written, and completed where it doesn't tell
 * what the files of the object do (its UUID, when it was created and modified).
 *
 * A failing cache is logged, never thrown: the metadata is then read from its file.
 */
final readonly class MetadataStore
{
    public function __construct(
        private ObjectPaths          $paths,
        private AdapterInterface     $io,
        private JsonStore            $json,
        private ?CacheInterface      $cache = null,
        private ?DispatcherInterface $dispatcher = null,
        private ?LoggerInterface     $logger = null,
    )
    {
    }

    /**
     * The metadata of an object, from the cache if it holds it, else from its file (and then cached).
     *
     * @return Metadata|null Null if there is none, or if it can't be read (logged, METADATA_LOADING_FAILURE
     *         dispatched).
     */
    public function load(string $uuid): ?Metadata
    {
        try {
            $cached = $this->cache?->get($uuid);
            if ($cached instanceof Metadata) {
                return $cached;
            }
        } catch (Throwable $e) {
            $this->logger?->log($e);
        }

        try {
            $filePathMetadata = $this->paths->metadataFile($uuid);

            // no metadata stored under this UUID: nothing to load, which is not a failure worth reporting
            if (false === $this->io->isFile($filePathMetadata)) {
                return null;
            }

            $metadata = $this->json->read($filePathMetadata);
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
                    $this->logger?->log(new Exception(sprintf('The metadata of %s named %s; corrected, written with the next change', $uuid, $named)));
                }
                $metadata->setUUID($uuid);
            }
            $this->completeTimestamps($metadata, $filePathMetadata);
            $this->cache?->set($uuid, $metadata);
            return $metadata;
        } catch (Throwable $e) {
            $this->dispatcher?->dispatch(Events::METADATA_LOADING_FAILURE, static fn() => new Context($uuid));
            $this->logger?->log($e);
        }
        return null;
    }

    /**
     * The metadata of an object from its file, bypassing and refreshing the cache: another process may have
     * changed it.
     */
    public function reload(string $uuid): ?Metadata
    {
        try {
            $this->cache?->delete($uuid);
        } catch (Throwable $e) {
            $this->logger?->log($e);
        }

        return $this->load($uuid);
    }

    /**
     * Writes metadata into its file, atomically (METADATA_SAVED dispatched), and caches it.
     *
     * @throws MetadataSavingFailureException If it can't be written (logged, METADATA_WRITE_FAILED dispatched).
     */
    public function write(Metadata $metadata): void
    {
        try {
            $this->json->write($this->paths->metadataFile($metadata->getUUID()), $metadata);
            $this->dispatcher?->dispatch(Events::METADATA_SAVED, static fn() => new Context($metadata->getUUID()));
        } catch (Throwable $e) {
            $this->dispatcher?->dispatch(Events::METADATA_WRITE_FAILED, static fn() => new Context($metadata->getUUID()));
            $this->logger?->log($e);
            throw new MetadataSavingFailureException(message: 'Unable to save metadata for uuid: ' . $metadata->getUUID(), previous: $e);
        }

        $this->cache($metadata);
    }

    /**
     * Removes the metadata of an object from the cache, e.g. once its files are deleted.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function forget(string $uuid): void
    {
        $this->cache?->delete($uuid);
    }

    /**
     * Puts metadata into the cache, e.g. metadata written with the data of its object.
     */
    public function cache(Metadata $metadata): void
    {
        try {
            $this->cache?->set($metadata->getUUID(), $metadata);
        } catch (Throwable $e) {
            $this->logger?->log($e);
        }
    }

    /**
     * Metadata not telling when the object was created or modified (written by other tools or by hand) takes the
     * times of its files: the modification that of the data file (when it was written last), the creation the
     * earliest time of the data and metadata files. As files are replaced when written, the creation is at most as
     * old as the last write: the best the file system knows. Written into the metadata with the next change.
     */
    private function completeTimestamps(Metadata $metadata, string $filePathMetadata): void
    {
        if (null !== $metadata->getTimestampCreation() && null !== $metadata->getTimestampModification()) {
            return;
        }

        $filePathData = $this->paths->dataFile((string)$metadata->getUUID());
        $times = [];
        foreach ([$filePathData, $filePathMetadata] as $file) {
            foreach ([$this->io->fileCTime($file), $this->io->fileMTime($file)] as $time) {
                if (false !== $time) {
                    $times[] = $time;
                }
            }
        }
        $modified = $this->io->fileMTime($filePathData);
        $modified = false === $modified ? $this->io->fileMTime($filePathMetadata) : $modified;

        if (null === $metadata->getTimestampModification() && false !== $modified) {
            $metadata->setTimestampModification($modified);
        }
        if (null === $metadata->getTimestampCreation() && [] !== $times) {
            $metadata->setTimestampCreation(min($times));
        }
    }
}
