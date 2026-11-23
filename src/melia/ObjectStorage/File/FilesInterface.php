<?php

namespace melia\ObjectStorage\File;

use melia\ObjectStorage\Exception\IOException;

/**
 * Reading and writing the files of the storage, as bytes: written atomically (readers see the previous or the new
 * contents, never a part), several files as a group, flushed to disk (fsync) unless disabled.
 *
 * The lowest of the layers the storage reads and writes its files with: JsonFiles encodes and decodes JSON on top
 * of it, the storage writes the data and the metadata of an object as a group with it.
 */
interface FilesInterface
{
    /**
     * The contents of a file.
     *
     * @return string|null Null if there is no such file.
     * @throws IOException If the file exists but can't be read.
     */
    public function read(string $path): ?string;

    /**
     * Writes a file atomically, replacing its previous contents; a missing directory is created.
     *
     * @throws IOException If the file can't be written; its previous contents are kept.
     */
    public function write(string $path, string $contents): void;

    /**
     * Writes several files as a group, so that after a crash either all or none of them hold the new contents,
     * once recover() has been called for the group. The files are committed in the given order.
     *
     * @param array<string, string> $files The files to write, path => contents, in commit order.
     * @throws IOException If a file can't be written. A failure before the first commit keeps the previous contents
     *         of all files; a later one is completed by recover().
     */
    public function writeGroup(array $files): void;

    /**
     * Completes a group write interrupted by a crash (see writeGroup()): either finishes it or discards it, so all
     * files of the group are consistent again.
     *
     * @param string[] $paths The files of the group, in the same order as written.
     * @return bool True if the files changed, i.e. an interrupted write was finished, false otherwise.
     * @throws IOException If the interrupted write can't be finished or discarded.
     */
    public function recover(array $paths): bool;

    /**
     * @return bool Whether written files are flushed to disk (fsync), so they survive a power loss or OS crash.
     */
    public function syncEnabled(): bool;

    /**
     * Enables or disables flushing written files to disk (fsync). Disable only where durability doesn't matter,
     * e.g. for caches or tests: it makes writes considerably faster.
     */
    public function setSyncEnabled(bool $enabled): void;
}
