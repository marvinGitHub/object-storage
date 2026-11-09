<?php

namespace melia\ObjectStorage\Journal;

/**
 * An entry of a journal, as appended.
 */
final readonly class Entry
{
    /**
     * @param int $sequence The number of the entry, counting from 1 without gaps.
     * @param string $type What the entry records, chosen by the writer, e.g. "begin" or "commit".
     * @param array<array-key, mixed> $payload The data of the entry, as appended (JSON types only).
     * @param float $time When it was appended, in seconds since 1970-01-01 UTC.
     */
    public function __construct(
        public int    $sequence,
        public string $type,
        public array  $payload,
        public float  $time,
    )
    {
    }
}
