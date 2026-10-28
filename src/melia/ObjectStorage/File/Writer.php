<?php

namespace melia\ObjectStorage\File;

use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\File\IO\AdapterAwareTrait;
use melia\ObjectStorage\File\IO\AdapterInterface;
use melia\ObjectStorage\File\IO\RealAdapter;
use Random\RandomException;

class Writer implements WriterInterface
{
    use AdapterAwareTrait;

    public const SUFFIX_STAGING = '.tmp';

    /**
     * Suffix of a file written ahead of its commit, e.g. "<uuid>.obj.pending".
     */
    public const SUFFIX_PENDING = '.pending';

    /**
     * @param bool $sync Whether written files (and the rename of them) are flushed to disk with fsync. Without it,
     *                   a power loss or OS crash can leave a renamed file empty or incomplete. Disable only where
     *                   durability doesn't matter, e.g. for caches or tests.
     */
    public function __construct(private bool $sync = true)
    {
    }

    /**
     * @return bool Whether written files are flushed to disk with fsync.
     */
    public function syncEnabled(): bool
    {
        return $this->sync;
    }

    /**
     * Enables or disables flushing written files to disk with fsync, see the constructor.
     *
     * @param bool $enabled Whether written files are flushed to disk.
     * @return void
     */
    public function setSyncEnabled(bool $enabled): void
    {
        $this->sync = $enabled;
    }

    /**
     * Performs an atomic write operation to the specified file. Ensures that the file content
     * is written safely and completely, handling directory creation if needed, and managing
     * file system operations to reduce risks of data loss or corruption.
     *
     * @param string $filename The path to the file to write. A missing directory is created.
     * @param string|null $data The data to write to the file. If null, an empty string will be written.
     * @return void
     * @throws IOException If any file operation such as opening, writing, truncating, or closing fails.
     */
    public function atomicWrite(string $filename, ?string $data = null): void
    {
        // a staging file unique per call, so concurrent writers of the same file don't interfere
        $stagingPath = $this->getStagingFilename($filename);
        $this->writeFile($stagingPath, $data);

        try {
            $this->moveFile($stagingPath, $filename);
        } catch (IOException $e) {
            $this->deleteIfExists($stagingPath);
            throw $e;
        }
    }

    /**
     * Writes several files as a group, so that after a crash either all or none of them hold the new content
     * (once recover() has been called for the group).
     *
     * All files are written as pending files first ("<file>.pending", flushed to disk), then committed one after
     * another in the given order by atomic renames. A crash before the first commit leaves the previous content
     * of all files; a crash in between leaves pending files that recover() commits.
     *
     * @param array<string, string|null> $files The files to write, path => content, in commit order.
     * @return void
     * @throws IOException If a file cannot be written or committed. A failure before the first commit leaves
     *                     the previous content of all files; a later failure is completed by recover().
     */
    public function atomicWriteAll(array $files): void
    {
        try {
            foreach ($files as $filename => $data) {
                $this->writePending($filename, $data);
            }
        } catch (IOException $e) {
            foreach (array_keys($files) as $filename) {
                $this->discardPending($filename);
            }
            throw $e;
        }

        // all pending files must exist on disk before anything is committed; flushing a directory persists
        // all its entries at once, so each directory is flushed once instead of once per pending file
        if ($this->sync) {
            foreach ($this->getDirectories(array_keys($files)) as $directory) {
                $this->syncDirectory($directory);
            }
        }

        foreach (array_keys($files) as $filename) {
            $this->commit($filename);
        }
    }

    /**
     * Completes a group write interrupted by a crash (see atomicWriteAll()).
     *
     * Commits happen in order and only after all pending files are completely written. So if the first file
     * still has a pending file, nothing was committed: all pending files are discarded and the previous content
     * stays. Otherwise committing had started, and the remaining pending files are committed.
     *
     * @param string[] $filenames The files of the group, in commit order.
     * @return bool True if pending files were committed, i.e. the files changed, false otherwise.
     * @throws IOException If a pending file cannot be committed or discarded.
     */
    public function recover(array $filenames): bool
    {
        $filenames = array_values($filenames);
        if ([] === $filenames) {
            return false;
        }

        $adapter = $this->getIOAdapter();

        if ($adapter->isFile($this->getPendingFilename($filenames[0]))) {
            foreach ($filenames as $filename) {
                $this->discardPending($filename);
            }
            return false;
        }

        $recovered = false;
        foreach ($filenames as $filename) {
            if ($adapter->isFile($this->getPendingFilename($filename))) {
                $this->commit($filename);
                $recovered = true;
            }
        }

        return $recovered;
    }

    /**
     * Writes the data to the pending file of a file (its content flushed to disk), leaving the file itself
     * untouched until commit() is called. The directory entry of the pending file is flushed by the caller,
     * once for all pending files of a group (see atomicWriteAll()).
     *
     * Unlike atomicWrite(), the pending file is written directly, without a staging file: an interrupted write
     * leaves an incomplete pending file, which is never committed (commit() is only called after all pending
     * files of a group are written) and is overwritten by the next write.
     *
     * Pending files are not locked (LOCK_EX would double the cost of a write): writers of the same files have
     * to be serialized by the caller anyway, since a group spans several files. The storage does this with the
     * exclusive lock on the object.
     *
     * @param string $filename The path of the actual file.
     * @param string|null $data The data to write. If null, an empty string will be written.
     * @return void
     * @throws IOException If the pending file cannot be written or flushed.
     */
    protected function writePending(string $filename, ?string $data = null): void
    {
        $this->writeFile($this->getPendingFilename($filename), $data);
    }

    /**
     * Replaces a file by its pending file with an atomic rename, and flushes the rename to disk.
     *
     * @param string $filename The path of the actual file.
     * @return void
     * @throws IOException If the pending file cannot be renamed, e.g. because there is none.
     */
    protected function commit(string $filename): void
    {
        $pendingFilename = $this->getPendingFilename($filename);

        // check first: on a failed rename the adapter deletes the target and retries, which would
        // delete the committed file if there is nothing to commit
        if (false === $this->getIOAdapter()->isFile($pendingFilename)) {
            throw new IOException('No pending file to commit: ' . $pendingFilename);
        }

        $this->moveFile($pendingFilename, $filename);
    }

    /**
     * Writes data to a file and flushes its content to disk, creating its directory if it doesn't exist. The file
     * is deleted again if writing fails, so no incomplete file remains.
     *
     * @param string $filename The path of the file to write.
     * @param string|null $data The data to write. If null, an empty string will be written.
     * @return void
     * @throws IOException If the directory cannot be created, or the file cannot be written completely or flushed.
     */
    protected function writeFile(string $filename, ?string $data): void
    {
        $adapter = $this->getIOAdapter();

        // directories already verified are remembered, so this costs a lookup only once per directory
        Directory::createIfNotExists($adapter, Directory::getDirectoryName($filename));

        $data = $data ?? '';
        $bytesWritten = $adapter->filePutContents($filename, $data);

        try {
            if (false === $bytesWritten || $bytesWritten !== strlen($data)) {
                throw new IOException('Failed to write to file: ' . $filename);
            }

            // the content must be on disk before the file is renamed, otherwise it may be empty after a crash
            if ($this->sync) {
                $this->syncFile($filename);
            }
        } catch (IOException $e) {
            $this->deleteIfExists($filename);
            throw $e;
        }
    }

    /**
     * Renames a file atomically and flushes the rename to disk.
     *
     * @param string $from The path of the file to rename.
     * @param string $to The new path, replacing an existing file.
     * @return void
     * @throws IOException If the file cannot be renamed.
     */
    protected function moveFile(string $from, string $to): void
    {
        if (false === $this->getIOAdapter()->rename($from, $to)) {
            throw new IOException('Failed to rename file: ' . $from);
        }

        if ($this->sync) {
            $this->syncDirectory(Directory::getDirectoryName($to));
        }
    }

    /**
     * Removes the pending file of a file, if there is one.
     *
     * @param string $filename The path of the actual file.
     * @return void
     * @throws IOException If the pending file exists but cannot be deleted.
     */
    protected function discardPending(string $filename): void
    {
        $this->deleteIfExists($this->getPendingFilename($filename));
    }

    /**
     * Deletes a file if it exists, e.g. a staging or pending file left by a failed write.
     *
     * @param string $filename The path of the file to delete.
     * @return void
     * @throws IOException If the file exists but cannot be deleted.
     */
    protected function deleteIfExists(string $filename): void
    {
        $adapter = $this->getIOAdapter();

        if ($adapter->isFile($filename) && false === $adapter->unlink($filename)) {
            throw new IOException('Unable to delete file: ' . $filename);
        }
    }

    /**
     * Returns a unique path for the staging file of a write, e.g. "<file>.tmp.<pid>.<random>". It is unique per call
     * to avoid contention between processes writing the same file, and in the same directory as the file, so the
     * final rename stays atomic (same filesystem).
     *
     * @param string $filename The path of the file to write.
     * @return string The path of the staging file.
     * @throws RandomException If no random bytes can be generated.
     */
    protected function getStagingFilename(string $filename): string
    {
        return $filename . static::SUFFIX_STAGING . '.' . getmypid() . '.' . bin2hex(random_bytes(8));
    }

    /**
     * Returns the path of the pending file of a file.
     *
     * @param string $filename The path of the actual file.
     * @return string The path of its pending file.
     */
    protected function getPendingFilename(string $filename): string
    {
        return $filename . static::SUFFIX_PENDING;
    }

    /**
     * Flushes the content of a file to disk.
     *
     * @param string $filename The file to flush.
     * @return void
     * @throws IOException If the file cannot be opened or flushed.
     */
    protected function syncFile(string $filename): void
    {
        $adapter = $this->getIOAdapter();
        $handle = $adapter->fopen($filename, 'r+');

        if (false === $handle) {
            throw new IOException('Unable to open file for syncing: ' . $filename);
        }

        try {
            if (false === $adapter->fsync($handle)) {
                throw new IOException('Unable to flush file to disk: ' . $filename);
            }
        } finally {
            $adapter->fclose($handle);
        }
    }

    /**
     * Flushes a directory to disk, so the creation or rename of files in it survives a crash.
     * Best effort: some filesystems don't support flushing directories, which is not treated as an error.
     *
     * @param string $directory The directory to flush.
     * @return void
     */
    protected function syncDirectory(string $directory): void
    {
        $this->getIOAdapter()->fsyncDirectory($directory);
    }

    /**
     * Returns the distinct directories of the given files.
     *
     * @param string[] $filenames The paths of the files.
     * @return string[] The directories, each once, in the order of their first file.
     */
    protected function getDirectories(array $filenames): array
    {
        return array_values(array_unique(array_map(Directory::getDirectoryName(...), $filenames)));
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