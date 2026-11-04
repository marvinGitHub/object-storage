<?php

namespace melia\ObjectStorage\Search\Index;

/**
 * An index considered for a search of a class, see Plan.
 */
final readonly class Candidate
{
    /**
     * @param string $name The name of the index.
     * @param string $engine The engine of the index.
     * @param string $status The status of the index, see IndexInterface.
     * @param string[] $reasons Why the index can't answer the search; empty if it can.
     * @param int|null $rank The position among the indexes that can answer the search, 1 for the chosen one; null if
     *                       it can't.
     * @param int $searchableFields The number of searchable fields of the searched definition.
     * @param int $fields The number of fields of the searched definition.
     */
    public function __construct(
        public string $name,
        public string $engine,
        public string $status,
        public array  $reasons,
        public ?int   $rank,
        public int    $searchableFields,
        public int    $fields,
    )
    {
    }

    public function isUsable(): bool
    {
        return [] === $this->reasons;
    }

    public function withRank(int $rank): self
    {
        return new self($this->name, $this->engine, $this->status, $this->reasons, $rank, $this->searchableFields, $this->fields);
    }
}
