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

    /**
     * Creates a recovery handler for managing cleanup operations in case of a failure during a file operation.
     * The returned closure ensures resources are properly closed and the file is deleted if necessary.
     *
     * @param resource|null $resource The file resource to close. Can be null if no resource needs to be closed.
     * @param string $filename The name of the file to be deleted during recovery.
     * @return Closure A closure that performs the recovery operations when invoked.
     */
    protected function createRecoveryHandler($resource, string $filename): Closure
    {
        $adapter = $this->getIOAdapter();
        return static function () use ($filename, $resource, $adapter) {
            if (is_resource($resource)) {
                if (false === $adapter->fclose($resource)) {
                    throw new IOException('Unable to close file: ' . $filename);
                }
            }

            if (false === $adapter->isFile($filename)) {
                return;
            }

            if (false === $adapter->unlink($filename)) {
                throw new IOException('Unable to delete file during recovery: ' . $filename);
            }
        };
    }

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

        $adapter = $this->getIOAdapter();
        if (null === $adapter) {
            throw new IOException('No adapter has been set');
        }

        /* do not use file_put_contents() here, because it does not support atomic writes */
        $file = $adapter->fopen($filename, 'w+');

        if (false === $file) {
            $this->createRecoveryHandler(null, $filename)();
            throw new IOException('Unable to open file for writing: ' . $filename);
        }

        if (false === $adapter->rewind($file)) {
            $this->createRecoveryHandler($file, $filename)();
            throw new IOException('Unable to rewind file: ' . $filename);
        }

        if (false === $adapter->fwrite($file, $data ?? '')) {
            $this->createRecoveryHandler($file, $filename)();
            throw new IOException('Unable to write to file: ' . $filename);
        }

        if (false === $adapter->fflush($file)) {
            $this->createRecoveryHandler($file, $filename)();
            throw new IOException('Unable to flush file: ' . $filename);
        }

        if (false === $position = $adapter->ftell($file)) {
            $this->createRecoveryHandler($file, $filename)();
            throw new IOException('Unable to get file position: ' . $filename);
        }

        if (false === $adapter->ftruncate($file, $position)) {
            $this->createRecoveryHandler($file, $filename)();
            throw new IOException('Unable to truncate file: ' . $filename);
        }

        if (false === $adapter->fclose($file)) {
            $this->createRecoveryHandler($file, $filename)();
            throw new IOException('Unable to close file: ' . $filename);
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