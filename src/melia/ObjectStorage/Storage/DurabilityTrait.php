<?php

namespace melia\ObjectStorage\Storage;

use melia\ObjectStorage\Exception\ChecksumMismatchException;
use melia\ObjectStorage\Exception\InvalidFileFormatException;
use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\Metadata\Metadata;
use Throwable;

/**
 * Durability of the files of objects (docs/durability.md), delegated to the component ObjectFiles: data and
 * metadata written as a group, read with the checksum verified, interrupted writes completed. Shared by load() and
 * readRaw(), store() and writeRaw().
 *
 * Part of ObjectStorage, kept apart for readability.
 *
 * @mixin \melia\ObjectStorage\ObjectStorage
 */
trait DurabilityTrait
{
    /**
     * The files of objects of the storage, with its paths, files, IO adapter, metadata, strategy, state handler,
     * event dispatcher and logger as they are now.
     */
    protected function objectFiles(): ObjectFiles
    {
        $paths = $this->objectPaths();
        $files = $this->getFiles();
        $io = $this->getIOAdapter();
        $metadata = $this->metadataStore();
        $strategy = $this->getStrategy();
        $stateHandler = $this->getStateHandler();
        $dispatcher = $this->getEventDispatcher();
        $logger = $this->getLogger();

        return $this->component(
            ObjectFiles::class,
            [$paths, $files, $io, $metadata, $strategy, $stateHandler, $dispatcher, $logger],
            static fn(): ObjectFiles => new ObjectFiles($paths, $files, $io, $metadata, $strategy, $stateHandler, $dispatcher, $logger),
        );
    }

    /**
     * Reads the stored data of an object together with the metadata it was written with, see ObjectFiles::read().
     * The caller holds the lock of the object.
     *
     * @return array{0: string, 1: Metadata, 2: string}|null The raw data, its metadata and the data file; null if
     *         nothing is stored under the UUID.
     * @throws InvalidFileFormatException If there is data without metadata.
     * @throws ChecksumMismatchException If the data doesn't match its checksum.
     * @throws IOException If the data file exists but can't be read.
     */
    protected function readObjectFiles(string $uuid, bool $safeModeOnCorruption = true): ?array
    {
        return $this->objectFiles()->read($uuid, $safeModeOnCorruption);
    }

    /**
     * Writes the data and the metadata of an object as a group, recorded in the active transaction first, see
     * ObjectFiles::write(). The caller holds the exclusive lock of the object.
     *
     * @throws Throwable
     */
    protected function writeObjectFiles(string $uuid, string $data, Metadata $metadata): void
    {
        // the state before is recorded first, so the transaction can restore it
        $this->getActiveTransaction()?->track($uuid);

        $this->objectFiles()->write($uuid, $data, $metadata);
    }

    /**
     * Completes a write of an object interrupted by a crash, see ObjectFiles::recover().
     *
     * @return bool True if the files changed, false otherwise or if the recovery failed.
     */
    protected function recoverInterruptedWrite(string $uuid): bool
    {
        return $this->objectFiles()->recover($uuid);
    }

    /**
     * The checksum of data, see ObjectFiles::checksum().
     */
    protected static function generateChecksum(string $data, string $algorithm): string
    {
        return ObjectFiles::checksum($data, $algorithm);
    }
}
