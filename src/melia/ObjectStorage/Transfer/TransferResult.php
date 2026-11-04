<?php

namespace melia\ObjectStorage\Transfer;

/**
 * What a copy or move did, per object.
 */
final class TransferResult
{
    /** @var string[] The objects copied (or moved). */
    public array $transferred = [];

    /** @var string[] Referenced objects copied along. */
    public array $references = [];

    /** @var array<string, string> Objects left out, UUID => reason (exists in the target, expired). */
    public array $skipped = [];

    /** @var array<string, string> Objects that couldn't be transferred, UUID => error. */
    public array $failed = [];

    /**
     * Adds the outcome of another batch.
     */
    public function merge(self $other): void
    {
        $this->transferred = [...$this->transferred, ...$other->transferred];
        $this->references = array_values(array_unique([...$this->references, ...$other->references]));
        $this->skipped = $other->skipped + $this->skipped;
        $this->failed = $other->failed + $this->failed;
    }

    /**
     * The number of objects handled: transferred, skipped or failed (referenced objects not counted).
     */
    public function count(): int
    {
        return count($this->transferred) + count($this->skipped) + count($this->failed);
    }

    /**
     * @return array{transferred: string[], references: string[], skipped: array<string, string>, failed: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'transferred' => $this->transferred,
            'references' => $this->references,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
        ];
    }
}
