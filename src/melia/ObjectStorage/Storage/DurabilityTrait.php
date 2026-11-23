<?php

namespace melia\ObjectStorage\Storage;

use melia\ObjectStorage\Exception\ChecksumMismatchException;
use melia\ObjectStorage\Exception\Exception;
use melia\ObjectStorage\Exception\InvalidFileFormatException;
use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\File\JsonFileReader;
use melia\ObjectStorage\File\JsonFileWriter;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\Strategy\StrategyInterface;
use Throwable;

/**
 * Durability of the files of objects (docs/durability.md): data and metadata written as a group, read with the
 * checksum verified, interrupted writes completed. Shared by load() and readRaw(), store() and writeRaw().
 *
 * Part of ObjectStorage, kept apart for readability.
 *
 * @mixin \melia\ObjectStorage\ObjectStorage
 */
trait DurabilityTrait
{
    /**
     * Reads the stored data of an object together with the metadata it was written with, as loading and readRaw()
     * need it: an interrupted write is completed first, and the checksum is verified (unless disabled in the
     * strategy). The caller holds the lock of the object.
     *
     * @param string $uuid The unique identifier of the object.
     * @param bool $safeModeOnCorruption Whether corrupted files enable safe mode, as when loading; false to report
     *        them only, e.g. while copying many objects, so one damaged object doesn't stop the others.
     * @return array{0: string, 1: Metadata, 2: string}|null The raw data, its metadata and the data file; null if
     *         nothing is stored under the UUID.
     * @throws InvalidFileFormatException If there is data without metadata.
     * @throws ChecksumMismatchException If the data doesn't match its checksum.
     */
    protected function readObjectFiles(string $uuid, bool $safeModeOnCorruption = true): ?array
    {
        // load metadata once and derive the class name and lifetime from it; a new object whose first write
        // was interrupted by a crash has no metadata file yet, recovering completes (or discards) that write
        $metadata = $this->loadMetadata($uuid);
        if (null === $metadata && $this->recoverInterruptedWrite($uuid)) {
            $metadata = $this->reloadMetadata($uuid);
        }

        $filename = $this->getFilePathData($uuid);

        if (null === $metadata) {
            // nothing stored under this UUID (never stored or deleted): not an error
            if (false === $this->getIOAdapter()->isFile($filename)) {
                return null;
            }

            // data without metadata: corrupted
            if ($safeModeOnCorruption) {
                $this->getStateHandler()?->enableSafeMode();
            }
            throw new InvalidFileFormatException('Unable to load metadata for: ' . $uuid);
        }

        $raw = $this->readDataFile($filename);
        if (null === $raw) {
            return null;
        }

        // the metadata to build the object from is the one the data was written with; data not matching its
        // metadata may be left by an interrupted write, which is completed before the files are read again
        $matchingMetadata = $this->findMatchingMetadata($uuid, $raw, $metadata);
        if (null === $matchingMetadata && $this->recoverInterruptedWrite($uuid)) {
            $raw = $this->readDataFile($filename);
            if (null === $raw) {
                return null;
            }
            $matchingMetadata = $this->findMatchingMetadata($uuid, $raw, $this->reloadMetadata($uuid) ?? $metadata);
        }

        if (null === $matchingMetadata) {
            $expected = $this->loadMetadata($uuid) ?? $metadata;
            if ($safeModeOnCorruption) {
                $this->getStateHandler()?->enableSafeMode();
            }
            throw new ChecksumMismatchException(sprintf(
                'Checksum mismatch for uuid %s. Current: %s Expected: %s (%s, metadata version %d)',
                $uuid,
                static::generateChecksum($raw, $expected->getChecksumAlgorithm()),
                $expected->getChecksum(),
                $expected->getChecksumAlgorithm(),
                $expected->getVersion()
            ));
        }

        return [$raw, $matchingMetadata, $filename];
    }

    /**
     * Reads the data file of an object.
     *
     * @return string|null The data; null if there is no data file (nothing stored, or deleted).
     * @throws IOException If the data file exists but can't be read: reported as an error, not as a missing object,
     *                     since callers create an object anew if none is stored (and would overwrite this one).
     */
    protected function readDataFile(string $filename): ?string
    {
        $raw = $this->getJsonFileReader()->read($filename);
        if (null === $raw && $this->getIOAdapter()->isFile($filename)) {
            throw new IOException('Unable to read the data file ' . $filename);
        }

        return $raw;
    }

    /**
     * Writes the data and the metadata of an object as a group, so a crash can't leave data without matching
     * metadata (see recoverInterruptedWrite()). The caller holds the exclusive lock of the object.
     *
     * @throws Throwable
     */
    protected function writeObjectFiles(string $uuid, string $data, Metadata $metadata): void
    {
        // the state before is recorded first, so the transaction can restore it
        $this->getActiveTransaction()?->track($uuid);

        [$filePathData, $filePathMetadata] = $this->getObjectFiles($uuid);
        $this->getWriter()->atomicWriteAll([
            $filePathData => $data,
            $filePathMetadata => $this->getJsonFileWriter()->encode($metadata),
        ]);
    }

    /**
     * Completes a write of an object interrupted by a crash, so its data and metadata files match again.
     *
     * @param string $uuid The unique identifier of the object.
     * @return bool True if the files changed, false otherwise or if the recovery failed.
     */
    protected function recoverInterruptedWrite(string $uuid): bool
    {
        try {
            $recovered = $this->getWriter()->recover($this->getObjectFiles($uuid));
        } catch (Throwable $e) {
            $this->getLogger()?->log($e);
            return false;
        }

        if ($recovered) {
            $this->getLogger()?->log(new Exception(sprintf('Recovered object %s from an interrupted write', $uuid)));
        }

        return $recovered;
    }

    /**
     * Metadata not telling when the object was created or modified (written by other tools or by hand) takes the
     * times of its files: the modification that of the data file (when it was written last), the creation the
     * earliest time of the data and metadata files. As files are replaced when written, the creation is at most as
     * old as the last write: the best the file system knows. Written into the metadata with the next change.
     */
    protected function completeTimestamps(Metadata $metadata, string $filePathMetadata): void
    {
        if (null !== $metadata->getTimestampCreation() && null !== $metadata->getTimestampModification()) {
            return;
        }

        $io = $this->getIOAdapter();
        $filePathData = $this->getFilePathData((string)$metadata->getUUID());
        $times = [];
        foreach ([$filePathData, $filePathMetadata] as $file) {
            foreach ([$io->fileCTime($file), $io->fileMTime($file)] as $time) {
                if (false !== $time) {
                    $times[] = $time;
                }
            }
        }
        $modified = $io->fileMTime($filePathData);
        $modified = false === $modified ? $io->fileMTime($filePathMetadata) : $modified;

        if (null === $metadata->getTimestampModification() && false !== $modified) {
            $metadata->setTimestampModification($modified);
        }
        if (null === $metadata->getTimestampCreation() && [] !== $times) {
            $metadata->setTimestampCreation(min($times));
        }
    }

    /**
     * Finds the metadata the raw data of an object was written with, by its checksum.
     *
     * The given metadata may come from the cache and be outdated, since another process may have updated
     * the object in the meantime. So on a mismatch the metadata is reloaded from disk once, and the same
     * data is verified again.
     *
     * @param string $uuid The unique identifier of the object.
     * @param string $data The raw data as read from the data file.
     * @param Metadata $metadata The metadata of the object, possibly from the cache.
     * @return Metadata|null The metadata matching the data (the given one or the one reloaded from disk),
     *                       or null if the data matches neither.
     */
    protected function findMatchingMetadata(string $uuid, string $data, Metadata $metadata): ?Metadata
    {
        if (false === $this->getStrategy()?->checksumValidationEnabled() || static::checksumMatches($data, $metadata)) {
            return $metadata;
        }

        $freshMetadata = $this->reloadMetadata($uuid);
        if (null !== $freshMetadata && static::checksumMatches($data, $freshMetadata)) {
            return $freshMetadata;
        }

        return null;
    }

    /**
     * Checks whether the checksum of the data equals the one recorded in the metadata.
     *
     * @param string $data The raw data.
     * @param Metadata $metadata The metadata holding the expected checksum and its algorithm.
     * @return bool True if the checksums are equal, false otherwise.
     */
    protected static function checksumMatches(string $data, Metadata $metadata): bool
    {
        return $metadata->getChecksum() === static::generateChecksum($data, $metadata->getChecksumAlgorithm());
    }

    /**
     * Generates a checksum for the given data using the specified hashing algorithm.
     *
     * @param string $data The input data for which the checksum is to be generated.
     * @param string $algorithm The hashing algorithm to use (e.g., 'sha256', 'md5', 'crc32b').
     * @return string The generated checksum as a hashed string.
     */
    protected static function generateChecksum(string $data, string $algorithm): string
    {
        return hash($algorithm, $data);
    }

    /**
     * Reads the files of the storage and decodes their JSON, with its reader, logger, events and maximum depth.
     * Created per use, so a reader or strategy set later is used.
     *
     * @param bool $safeModeOnCorruption Whether invalid JSON enables safe mode (a damaged file of the storage);
     *        false to report it only.
     */
    protected function getJsonFileReader(bool $safeModeOnCorruption = true): JsonFileReader
    {
        return new JsonFileReader(
            $this->getReader(),
            $this->getStrategy()?->getMaxDepth() ?? StrategyInterface::DEFAULT_MAX_DEPTH,
            $this->getLogger(),
            $this->getEventDispatcher(),
            $safeModeOnCorruption ? fn() => $this->getStateHandler()?->enableSafeMode() : null,
        );
    }

    /**
     * Encodes JSON and writes the files of the storage atomically, with its writer and maximum depth. Created per
     * use, so a writer or strategy set later is used.
     */
    protected function getJsonFileWriter(): JsonFileWriter
    {
        return new JsonFileWriter($this->getWriter(), $this->getStrategy()?->getMaxDepth() ?? StrategyInterface::DEFAULT_MAX_DEPTH);
    }
}
