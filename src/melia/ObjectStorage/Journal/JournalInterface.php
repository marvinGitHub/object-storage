<?php

namespace melia\ObjectStorage\Journal;

use melia\ObjectStorage\Journal\Exception\CorruptedJournalException;
use melia\ObjectStorage\Journal\Exception\JournalException;

/**
 * An append-only log of entries, numbered without gaps, that survives crashes: an entry is either completely in
 * the journal or not at all.
 */
interface JournalInterface
{
    /**
     * Appends an entry. With sync enabled, it is on disk when this returns.
     *
     * @param string $type What the entry records.
     * @param array<array-key, mixed> $payload The data of the entry; JSON types only.
     * @return int The sequence number of the entry.
     * @throws JournalException If it can't be written.
     */
    public function append(string $type, array $payload = []): int;

    /**
     * Reads the entries in the order they were appended.
     *
     * @param int $afterSequence Only entries with a greater sequence number.
     * @return iterable<Entry>
     * @throws CorruptedJournalException If an entry before the last one is damaged.
     * @throws JournalException If the journal can't be read.
     */
    public function read(int $afterSequence = 0): iterable;

    /**
     * The sequence number of the last entry, 0 for an empty journal.
     *
     * @throws JournalException
     */
    public function lastSequence(): int;

    /**
     * Removes the entries up to a sequence number, as far as they fill whole segments: entries sharing a segment
     * with later ones, and the last segment, are kept.
     *
     * @return int The number of segments removed.
     * @throws JournalException
     */
    public function prune(int $upToSequence): int;

    /**
     * Removes the journal with all its entries and its directory.
     *
     * @throws JournalException
     */
    public function remove(): void;

    /**
     * Whether the journal has been written to, i.e. its directory exists.
     */
    public function exists(): bool;

    /**
     * The directory the journal is kept in.
     */
    public function getDirectory(): string;
}
