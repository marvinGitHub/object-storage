<?php

namespace melia\ObjectStorage\Unique\Exception;

/**
 * Objects stored before share values, so the unique index can't be created (or rebuilt) — like a database
 * refusing a unique index on duplicate data.
 */
class DuplicateValuesException extends UniqueIndexException
{
    /**
     * @param string $indexName The unique index.
     * @param array<string, string[]> $duplicates The values (as JSON) => the UUIDs of the objects sharing them.
     */
    public function __construct(private readonly string $indexName, private readonly array $duplicates)
    {
        // a few examples: all of them may be thousands (getDuplicates())
        $examples = array_slice($this->duplicates, 0, 3, true);
        parent::__construct(sprintf(
            'Unique index "%s": %d value(s) are shared by several objects, e.g. %s. Resolve them first.',
            $indexName,
            count($duplicates),
            implode('; ', array_map(
                static fn(string $key, array $uuids): string => sprintf(
                    '%s by %s%s',
                    $key,
                    implode(', ', array_slice($uuids, 0, 3)),
                    count($uuids) > 3 ? sprintf(' and %d more', count($uuids) - 3) : ''
                ),
                array_keys($examples),
                $examples
            ))
        ));
    }

    public function getIndexName(): string
    {
        return $this->indexName;
    }

    /**
     * @return array<string, string[]> The values (as JSON) => the UUIDs of the objects sharing them.
     */
    public function getDuplicates(): array
    {
        return $this->duplicates;
    }
}
