<?php

namespace melia\ObjectStorage\File;

use Closure;
use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\File\IO\AdapterAwareTrait;
use melia\ObjectStorage\File\IO\AdapterInterface;
use melia\ObjectStorage\File\IO\RealAdapter;

class Writer implements WriterInterface
{
    use AdapterAwareTrait;

    const SUFFIX_STAGING = '.tmp';

    /**
     * Performs an atomic write operation to the specified file. Ensures that the file content
     * is written safely and completely, handling directory creation if needed, and managing
     * file system operations to reduce risks of data loss or corruption.
     *
     * @param string $filename The path to the file to write.
     * @param string|null $data The data to write to the file. If null, an empty string will be written.
     * @param bool $createDirectoryIfNotExist Indicates whether the parent directory should be created if it does not exist.
     * @return void
     * @throws IOException If any file operation such as opening, writing, truncating, or closing fails.
     */
    public function atomicWrite(string $filename, ?string $data = null, bool $createDirectoryIfNotExist = false): void
    {
        if ($createDirectoryIfNotExist) {
            $directory = new Directory(Directory::getDirectoryName($filename));
            $directory->createIfNotExists();
        }

        $data = $data ?? '';

        $adapter = $this->getIOAdapter();
        if (null === $adapter) {
            throw new IOException('No adapter has been set');
        }

        // Use a unique staging path to avoid cross-process contention on "<file>.tmp"
        // Keep it in the same directory so rename stays atomic (same filesystem).
        $stagingPath = $filename . static::SUFFIX_STAGING . '.' . getmypid() . '.' . bin2hex(random_bytes(8));

        // No LOCK_EX needed: a staging file is unique per call
        $bytesWritten = $adapter->filePutContents($stagingPath, $data);

        if (false === $bytesWritten || $bytesWritten !== strlen($data)) {
            $this->createRecoveryHandler($stagingPath)();
            throw new IOException('Failed to write to file: ' . $filename);
        }

        if (false === $adapter->rename($stagingPath, $filename)) {
            $this->createRecoveryHandler($stagingPath)();
            throw new IOException('Failed to rename file: ' . $stagingPath);
        }
    }

    /**
     * Retrieves the adapter instance used for input/output operations.
     * If the adapter is not already initialized, a new instance of RealAdapter is created and assigned.
     *
     * @return AdapterInterface|null The adapter instance or null if not set.
     */
    public function getIOAdapter(): ?AdapterInterface
    {
        if (null === $this->ioAdapter) {
            $this->ioAdapter = new RealAdapter();
        }
        return $this->ioAdapter;
    }

    /**
     * Creates a recovery handler for managing cleanup operations in case of a failure during a file operation.
     * The returned closure ensures resources are properly closed and the file is deleted if necessary.
     *
     * @param string $filename The name of the file to be deleted during recovery.
     * @return Closure A closure that performs the recovery operations when invoked.
     */
    protected function createRecoveryHandler(string $filename): Closure
    {
        $adapter = $this->getIOAdapter();
        return static function () use ($filename, $adapter) {
            if (false === $adapter->isFile($filename)) {
                return;
            }

            if (false === $adapter->unlink($filename)) {
                throw new IOException('Unable to delete file during recovery: ' . $filename);
            }
        };
    }

    /**
     * Creates an empty file with the specified filename.
     *
     * @param string $filename The name of the file to be created.
     * @return void
     */
    public function createEmptyFile(string $filename): void
    {
        $this->getIOAdapter()->touch($filename);
    }
}