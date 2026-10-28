<?php

namespace melia\ObjectStorage\File;

interface WriterInterface
{

    public function atomicWrite(string $filename, ?string $data = null): void;

    /**
     * @return bool Whether written files are flushed to disk (fsync), so they survive a power loss or OS crash.
     */
    public function syncEnabled(): bool;

    /**
     * Enables or disables flushing written files to disk (fsync). Disable only where durability doesn't matter,
     * e.g. for caches or tests: it makes writes considerably faster.
     *
     * @param bool $enabled Whether written files are flushed to disk.
     */
    public function setSyncEnabled(bool $enabled): void;

    /**
     * Writes several files as a group, so that after a crash either all or none of them hold the new content,
     * once recover() has been called for the group. The files are committed in the given order.
     *
     * @param array<string, string|null> $files The files to write, path => content, in commit order.
     */
    public function atomicWriteAll(array $files): void;

    /**
     * Completes a group write interrupted by a crash (see atomicWriteAll()): either finishes it or discards it,
     * so all files of the group are consistent again.
     *
     * @param string[] $filenames The files of the group, in the same order as written.
     * @return bool True if the files changed, i.e. an interrupted write was finished, false otherwise.
     */
    public function recover(array $filenames): bool;
}
