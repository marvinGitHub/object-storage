<?php

namespace melia\ObjectStorage\Path;

/**
 * A segment of a path that isn't a plain name (plain names are strings): all elements (*), an element of a list by
 * its position ([0], [-1] for the last) or a key written in brackets (['alex'], also keys with dots or spaces).
 */
final readonly class PathSegment
{
    public const string WILDCARD = 'wildcard';
    public const string INDEX = 'index';
    public const string KEY = 'key';

    private function __construct(public string $kind, public int|string|null $value = null)
    {
    }

    /** all elements of a list (or values of a map): friends.*.name */
    public static function wildcard(): self
    {
        return new self(self::WILDCARD);
    }

    /** the element of a list at a position, from 0; negative from the end: friends[0], friends[-1] */
    public static function index(int $index): self
    {
        return new self(self::INDEX, $index);
    }

    /** the value of a key, exactly (never applied to the elements of a list): friends['alex'] */
    public static function key(string $key): self
    {
        return new self(self::KEY, $key);
    }

    public function isWildcard(): bool
    {
        return self::WILDCARD === $this->kind;
    }

    /** as written in a path, e.g. "*", "[0]", "['alex']" */
    public function __toString(): string
    {
        return match ($this->kind) {
            self::WILDCARD => '*',
            self::INDEX => '[' . $this->value . ']',
            default => "['" . str_replace("'", "''", (string)$this->value) . "']",
        };
    }
}
