<?php

namespace melia\ObjectStorage\Search\Index;

/**
 * Which index answers a search of a class, and why: the result of ManagerInterface::explain(), like EXPLAIN of a
 * database.
 */
final readonly class Plan
{
    /**
     * @param string $className The searched class.
     * @param Candidate[] $candidates All indexes of the class: the usable ones by rank, then the others.
     */
    public function __construct(
        public string $className,
        public array  $candidates,
    )
    {
    }

    /**
     * The chosen index, null if no index of the class can answer the search.
     */
    public function getChosen(): ?Candidate
    {
        foreach ($this->candidates as $candidate) {
            if (1 === $candidate->rank) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * A readable explanation, e.g. for logs and exceptions.
     */
    public function describe(): string
    {
        if ([] === $this->candidates) {
            return sprintf('No search index exists for %s.', $this->className);
        }

        $lines = [];
        foreach ($this->candidates as $candidate) {
            $lines[] = sprintf(
                '%s %s (%s, %s): %s',
                1 === $candidate->rank ? '*' : '-',
                $candidate->name,
                $candidate->engine,
                $candidate->status,
                $candidate->isUsable() ? 'usable, rank ' . $candidate->rank : implode('; ', $candidate->reasons)
            );
        }

        return sprintf("Search indexes of %s:\n%s", $this->className, implode("\n", $lines));
    }
}
