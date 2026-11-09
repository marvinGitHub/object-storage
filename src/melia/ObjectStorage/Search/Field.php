<?php

namespace melia\ObjectStorage\Search;

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
     *                     A referenced object is indexed as its UUID.
     * @param FieldType $type The type the value is converted to.
     * @param bool $searchable Whether the full-text search covers the field.
     * @param bool $filterable Whether the field can be used in filters (and facets).
     * @param bool $sortable Whether results can be sorted by the field.
     * @throws InvalidIndexDefinitionException
     */
    public function __construct(
        public string    $name,
        public string    $path,
        public FieldType $type = FieldType::STRING,
        public bool      $searchable = true,
        public bool      $filterable = false,
        public bool      $sortable = false,
    )
    {
        if (!preg_match(self::NAME_PATTERN, $name) || strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidIndexDefinitionException(sprintf('Invalid field name "%s": use letters, digits and underscores, starting with a letter.', $name));
        }

        if (in_array($name, Definition::RESERVED_ATTRIBUTES, true)) {
            throw new InvalidIndexDefinitionException(sprintf('The field name "%s" is reserved.', $name));
        }

        if ('' === trim($path) || str_contains($path, '..') || str_starts_with($path, '.') || str_ends_with($path, '.')) {
            throw new InvalidIndexDefinitionException(sprintf('Invalid path "%s" for field "%s".', $path, $name));
        }

        if ($sortable && $type->isList()) {
            throw new InvalidIndexDefinitionException(sprintf('The field "%s" is a list and can\'t be sortable.', $name));
        }
    }

    /**
     * @return string[] The segments of the path.
     */
    public function getPathSegments(): array
    {
        return explode('.', $this->path);
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'path' => $this->path,
            'type' => $this->type->value,
            'searchable' => $this->searchable,
            'filterable' => $this->filterable,
            'sortable' => $this->sortable,
        ];
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
        );
    }
}
