<?php

namespace melia\ObjectStorage\Search;

use melia\ObjectStorage\Path\Exception\InvalidPathException;
use melia\ObjectStorage\Path\Path;
use melia\ObjectStorage\Path\PathSegment;
use melia\ObjectStorage\Search\Exception\InvalidIndexDefinitionException;
use melia\ObjectStorage\Search\Index\Definition;

/**
 * A field of a search index: a value from the stored data of an object, written into the search document
 * as an attribute.
 */
final readonly class Field
{
    /** field names: letters, digits and underscores, starting with a letter; accepted by the usual search engines */
    public const string NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_]*$/';
    public const int MAX_NAME_LENGTH = 64;

    /**
     * @param string $name The attribute name in the search index, e.g. "city".
     * @param string $path The path of the value in the stored data, separated by dots, e.g. "address.city".
     *                     A referenced object is indexed as its UUID. Lists: "friends.*.name" (all elements),
     *                     "friends[0].name" (by position), "friends['alex'].name" (by key), see Path.
     * @param FieldType $type The type the value is converted to.
     * @param bool $searchable Whether the full-text search covers the field.
     * @param bool $filterable Whether the field can be used in filters (and facets).
     * @param bool $sortable Whether results can be sorted by the field.
     * @param PatternIndex[] $patterns The indexes for patterns (LIKE, CONTAINS) of a filterable field of strings:
     *        TRIE for prefixes ('Ho%'), REVERSE_TRIE for suffixes ('%son'), NGRAM for parts anywhere ('%test%');
     *        without, a pattern is matched against all distinct values of the field.
     * @throws InvalidIndexDefinitionException
     */
    public function __construct(
        public string    $name,
        public string    $path,
        public FieldType $type = FieldType::STRING,
        public bool      $searchable = true,
        public bool      $filterable = false,
        public bool      $sortable = false,
        public array     $patterns = [],
    )
    {
        if (!preg_match(self::NAME_PATTERN, $name) || strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidIndexDefinitionException(sprintf('Invalid field name "%s": use letters, digits and underscores, starting with a letter.', $name));
        }

        if (in_array($name, Definition::RESERVED_ATTRIBUTES, true)) {
            throw new InvalidIndexDefinitionException(sprintf('The field name "%s" is reserved.', $name));
        }

        if ('' === trim($path)) {
            throw new InvalidIndexDefinitionException(sprintf('Invalid path "%s" for field "%s".', $path, $name));
        }
        try {
            Path::parse($path);
        } catch (InvalidPathException $e) {
            throw new InvalidIndexDefinitionException(sprintf('Invalid path "%s" for field "%s": %s', $path, $name, $e->getMessage()), 0, $e);
        }

        if ($sortable && $type->isList()) {
            throw new InvalidIndexDefinitionException(sprintf('The field "%s" is a list and can\'t be sortable.', $name));
        }

        if ([] !== $patterns) {
            if (!array_is_list($patterns) || !array_all($patterns, static fn(mixed $pattern): bool => $pattern instanceof PatternIndex)) {
                throw new InvalidIndexDefinitionException(sprintf('The patterns of the field "%s" must be a list of %s.', $name, PatternIndex::class));
            }
            if (count($patterns) !== count(array_unique(array_map(static fn(PatternIndex $pattern): string => $pattern->value, $patterns)))) {
                throw new InvalidIndexDefinitionException(sprintf('The field "%s" lists a pattern index twice.', $name));
            }
            if (!in_array($type, [FieldType::STRING, FieldType::STRING_ARRAY], true)) {
                throw new InvalidIndexDefinitionException(sprintf('Pattern indexes need a field of strings, "%s" is of the type %s.', $name, $type->value));
            }
            if (!$filterable) {
                throw new InvalidIndexDefinitionException(sprintf('Pattern indexes need a filterable field, "%s" isn\'t one.', $name));
            }
        }
    }

    /**
     * @return list<string|PathSegment> The segments of the path, see Path::parse().
     */
    public function getPathSegments(): array
    {
        return Path::parse($this->path);
    }

    /**
     * The attributes of the pattern indexes of the field, e.g. ["name__ngram" => PatternIndex::NGRAM].
     *
     * @return array<string, PatternIndex>
     */
    public function patternAttributes(): array
    {
        $attributes = [];
        foreach ($this->patterns as $pattern) {
            $attributes[$pattern->attribute($this->name)] = $pattern;
        }
        return $attributes;
    }

    public function hasPattern(PatternIndex $pattern): bool
    {
        return in_array($pattern, $this->patterns, true);
    }

    public function toArray(): array
    {
        $array = [
            'name' => $this->name,
            'path' => $this->path,
            'type' => $this->type->value,
            'searchable' => $this->searchable,
            'filterable' => $this->filterable,
            'sortable' => $this->sortable,
        ];
        // only with patterns: definitions stored before compare equal
        if ([] !== $this->patterns) {
            $array['patterns'] = array_map(static fn(PatternIndex $pattern): string => $pattern->value, $this->patterns);
        }
        return $array;
    }

    /**
     * @throws InvalidIndexDefinitionException
     */
    public static function fromArray(array $data): self
    {
        $type = FieldType::tryFrom((string)($data['type'] ?? FieldType::STRING->value));
        if (null === $type) {
            throw new InvalidIndexDefinitionException(sprintf('Invalid type "%s" for field "%s".', $data['type'], $data['name'] ?? ''));
        }

        return new self(
            name: (string)($data['name'] ?? ''),
            path: (string)($data['path'] ?? $data['name'] ?? ''),
            type: $type,
            searchable: (bool)($data['searchable'] ?? true),
            filterable: (bool)($data['filterable'] ?? false),
            sortable: (bool)($data['sortable'] ?? false),
            patterns: array_map(static function (mixed $pattern) use ($data): PatternIndex {
                $index = is_string($pattern) ? PatternIndex::tryFrom($pattern) : ($pattern instanceof PatternIndex ? $pattern : null);
                return $index ?? throw new InvalidIndexDefinitionException(sprintf('Invalid pattern index "%s" for field "%s": use %s.', is_scalar($pattern) ? $pattern : get_debug_type($pattern), $data['name'] ?? '', implode(', ', array_map(static fn(PatternIndex $case): string => $case->value, PatternIndex::cases()))));
            }, array_values((array)($data['patterns'] ?? []))),
        );
    }
}
