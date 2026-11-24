<?php

namespace melia\ObjectStorage\Storage;

use melia\ObjectStorage\Event\Context\IOContext;
use melia\ObjectStorage\Event\DispatcherInterface;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\ChecksumMismatchException;
use melia\ObjectStorage\Exception\Exception;
use melia\ObjectStorage\Exception\InvalidFileFormatException;
use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\File\FilesInterface;
use melia\ObjectStorage\File\IO\AdapterInterface;
use melia\ObjectStorage\File\Json;
use melia\ObjectStorage\Logger\LoggerInterface;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\State\StateHandler;
use melia\ObjectStorage\Strategy\StrategyInterface;
use Throwable;

/**
 * The files of objects (docs/durability.md): data and metadata written as a group, read with the checksum verified,
 * interrupted writes completed. The caller holds the lock of the object.
 */
final readonly class ObjectFiles
{
    public function __construct(
        private ObjectPaths          $paths,
        private FilesInterface       $files,
        private AdapterInterface     $io,
        private MetadataStore        $metadata,
        private ?StrategyInterface   $strategy = null,
        private ?StateHandler        $stateHandler = null,
        private ?DispatcherInterface $dispatcher = null,
        private ?LoggerInterface     $logger = null,
    )
    {
    }

    /**
     * Reads the stored data of an object together with the metadata it was written with: an interrupted write is
     * completed first, and the checksum is verified (unless disabled in the strategy).
     *
     * @param bool $safeModeOnCorruption Whether corrupted files enable safe mode, as when loading; false to report
     *        them only, e.g. while copying many objects, so one damaged object doesn't stop the others.
     * @return array{0: string, 1: Metadata, 2: string}|null The raw data, its metadata and the data file; null if
     *         nothing is stored under the UUID.
     * @throws InvalidFileFormatException If there is data without metadata.
     * @throws ChecksumMismatchException If the data doesn't match its checksum.
     * @throws IOException If the data file exists but can't be read.
     */
    public function read(string $uuid, bool $safeModeOnCorruption = true): ?array
    {
        // load metadata once and derive the class name and lifetime from it; a new object whose first write
        // was interrupted by a crash has no metadata file yet, recovering completes (or discards) that write
        $metadata = $this->metadata->load($uuid);
        if (null === $metadata && $this->recover($uuid)) {
            $metadata = $this->metadata->reload($uuid);
        }

        $filename = $this->paths->dataFile($uuid);

        if (null === $metadata) {
            // nothing stored under this UUID (never stored or deleted): not an error
            if (false === $this->io->isFile($filename)) {
                return null;
            }

            // data without metadata: corrupted
            if ($safeModeOnCorruption) {
                $this->stateHandler?->enableSafeMode();
            }
            throw new InvalidFileFormatException('Unable to load metadata for: ' . $uuid);
        }

        $raw = $this->readData($filename);
        if (null === $raw) {
            return null;
        }

        // the metadata to build the object from is the one the data was written with; data not matching its
        // metadata may be left by an interrupted write, which is completed before the files are read again
        $matchingMetadata = $this->findMatchingMetadata($uuid, $raw, $metadata);
        if (null === $matchingMetadata && $this->recover($uuid)) {
            $raw = $this->readData($filename);
            if (null === $raw) {
                return null;
            }
            $matchingMetadata = $this->findMatchingMetadata($uuid, $raw, $this->metadata->reload($uuid) ?? $metadata);
        }

        if (null === $matchingMetadata) {
            $expected = $this->metadata->load($uuid) ?? $metadata;
            if ($safeModeOnCorruption) {
                $this->stateHandler?->enableSafeMode();
            }
            throw new ChecksumMismatchException(sprintf(
                'Checksum mismatch for uuid %s. Current: %s Expected: %s (%s, metadata version %d)',
                $uuid,
                self::checksum($raw, $expected->getChecksumAlgorithm()),
                $expected->getChecksum(),
                $expected->getChecksumAlgorithm(),
                $expected->getVersion()
            ));
        }

        return [$raw, $matchingMetadata, $filename];
    }

    /**
     * Writes the data and the metadata of an object as a group, so a crash can't leave data without matching
     * metadata (see recover()). The caller holds the exclusive lock of the object.
     *
     * @throws Throwable
     */
    public function write(string $uuid, string $data, Metadata $metadata): void
    {
        [$filePathData, $filePathMetadata] = $this->paths->objectFiles($uuid);
        $this->files->writeGroup([
            $filePathData => $data,
            $filePathMetadata => (new Json())->encode($metadata),
        ]);
    }

    /**
     * Completes a write of an object interrupted by a crash, so its data and metadata files match again.
     *
     * @return bool True if the files changed, false otherwise or if the recovery failed (logged).
     */
    public function recover(string $uuid): bool
    {
        try {
            $recovered = $this->files->recover($this->paths->objectFiles($uuid));
        } catch (Throwable $e) {
            $this->logger?->log($e);
            return false;
        }

        if ($recovered) {
            $this->logger?->log(new Exception(sprintf('Recovered object %s from an interrupted write', $uuid)));
        }

        return $recovered;
    }

    /**
     * The checksum of data, as recorded in its metadata.
     *
     * @param string $algorithm The hashing algorithm (e.g. 'sha256', 'md5', 'crc32b').
     */
    public static function checksum(string $data, string $algorithm): string
    {
        return hash($algorithm, $data);
    }

    /**
     * Reads the data file of an object.
     *
     * @return string|null The data; null if there is no data file (nothing stored, or deleted).
     * @throws IOException If the data file exists but can't be read (logged, IO_READ_FAILURE dispatched): reported as
     *         an error, not as a missing object, since callers create an object anew if none is stored (and would
     *         overwrite this one).
     */
    private function readData(string $filename): ?string
    {
        try {
            return $this->files->read($filename);
        } catch (IOException $e) {
            $this->logger?->log($e);
            $this->dispatcher?->dispatch(Events::IO_READ_FAILURE, static fn() => new IOContext($filename));
            throw $e;
        }
    }

    /**
     * Finds the metadata the raw data of an object was written with, by its checksum.
     *
     * The given metadata may come from the cache and be outdated, since another process may have updated the object
     * in the meantime. So on a mismatch the metadata is reloaded from disk once, and the same data is verified again.
     *
     * @return Metadata|null The metadata matching the data (the given one or the one reloaded from disk), or null if
     *         the data matches neither.
     */
    private function findMatchingMetadata(string $uuid, string $data, Metadata $metadata): ?Metadata
    {
        if (false === $this->strategy?->checksumValidationEnabled() || self::matches($data, $metadata)) {
            return $metadata;
        }

        $freshMetadata = $this->metadata->reload($uuid);
        if (null !== $freshMetadata && self::matches($data, $freshMetadata)) {
            return $freshMetadata;
        }

        return null;
    }

    private static function matches(string $data, Metadata $metadata): bool
    {
        return $metadata->getChecksum() === self::checksum($data, $metadata->getChecksumAlgorithm());
    }
}
