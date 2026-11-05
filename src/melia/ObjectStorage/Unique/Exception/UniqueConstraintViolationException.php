<?php

namespace melia\ObjectStorage\Unique\Exception;

use melia\ObjectStorage\Exception\ConstraintViolationException;

/**
 * An object has the values of a unique index that another object has already, so it wasn't written.
 */
class UniqueConstraintViolationException extends ConstraintViolationException
{
    /**
     * @param string $indexName The unique index.
     * @param array<string, mixed> $values The values, path => value.
     * @param string $uuid The object that wasn't written.
     * @param string $owner The object that has the values.
     */
    public function __construct(
        private readonly string $indexName,
        private readonly array  $values,
        private readonly string $uuid,
        private readonly string $owner,
    )
    {
        parent::__construct(sprintf(
            'Unique index "%s": %s exists already (object %s), so %s can\'t have it.',
            $indexName,
            implode(', ', array_map(static fn(string $path, mixed $value): string => sprintf('%s = %s', $path, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), array_keys($values), $values)),
            $owner,
            $uuid
        ));
    }

    public function getIndexName(): string
    {
        return $this->indexName;
    }

    /**
     * @return array<string, mixed> path => value
     */
    public function getValues(): array
    {
        return $this->values;
    }

    public function getUUID(): string
    {
        return $this->uuid;
    }

    /**
     * The object that has the values already.
     */
    public function getOwner(): string
    {
        return $this->owner;
    }
}
