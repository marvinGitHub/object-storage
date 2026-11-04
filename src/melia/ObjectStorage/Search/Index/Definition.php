<?php

namespace melia\ObjectStorage\Search\Index;

use melia\ObjectStorage\Search\Exception\InvalidIndexDefinitionException;
use melia\ObjectStorage\Search\Field;

/**
 * Defines a search index: which objects (one class), which of their fields are indexed, and the search engine
 * that indexes them. It doesn't depend on the engine; engines may reject definitions they can't handle.
 */
final readonly class Definition
{
    /**
     * attributes every document has: the UUID of the object (primary key), its revision, and when it expires
     * (seconds since the epoch, null if never), to remove expired objects from the index
     */
    public const array RESERVED_ATTRIBUTES = ['uuid', 'revision', 'expiresAt'];

    public const string NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    /** @var Field[] */
    public array $fields;

    /**
     * @param string $name The name of the index, unique per storage; also its directory: lowercase letters,
     *                     digits, "_" and "-".
     * @param string $className The class of the indexed objects (exactly this class, not its subclasses).
     * @param Field[] $fields The indexed fields.
     * @param string[] $languages ISO 639-1 codes of the languages of the content (e.g. ['de', 'en']), used for
     *                            stemming by engines that support it; empty to detect them automatically.
     * @param string|null $engine The name of the search engine, e.g. "loupe"; null for the default engine of the
     *                            index manager.
     * @throws InvalidIndexDefinitionException
     */
    public function __construct(
        public string  $name,
        public string  $className,
        array          $fields,
        public array   $languages = [],
        public ?string $engine = null,
    )
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new InvalidIndexDefinitionException(sprintf('Invalid index name "%s": use lowercase letters, digits, "_" and "-".', $name));
        }

        if ('' === trim($className)) {
            throw new InvalidIndexDefinitionException('The class name of the index is missing.');
        }

        if ([] === $fields) {
            throw new InvalidIndexDefinitionException('An index needs at least one field.');
        }

        $byName = [];
        foreach ($fields as $field) {
            if (!$field instanceof Field) {
                throw new InvalidIndexDefinitionException('Fields must be instances of ' . Field::class . '.');
            }
            if (isset($byName[$field->name])) {
                throw new InvalidIndexDefinitionException(sprintf('The field "%s" is defined twice.', $field->name));
            }
            $byName[$field->name] = $field;
        }

        foreach ($languages as $language) {
            if (!is_string($language) || !preg_match('/^[a-z]{2}$/', $language)) {
                throw new InvalidIndexDefinitionException(sprintf('Invalid language "%s": use ISO 639-1 codes such as "de".', $language));
            }
        }

        if (null !== $engine && !preg_match('/^[a-z][a-z0-9]{0,31}$/', $engine)) {
            throw new InvalidIndexDefinitionException(sprintf('Invalid engine name "%s".', $engine));
        }

        $this->fields = array_values($byName);
    }

    /**
     * A copy for another engine.
     *
     * @throws InvalidIndexDefinitionException
     */
    public function withEngine(string $engine): self
    {
        return new self($this->name, $this->className, $this->fields, $this->languages, $engine);
    }

    /**
     * @param callable(Field): bool $filter
     * @return string[] The names of the fields matching the filter, e.g. the searchable ones.
     */
    public function fieldNames(callable $filter): array
    {
        return array_values(array_map(
            static fn(Field $field): string => $field->name,
            array_filter($this->fields, $filter)
        ));
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'className' => $this->className,
            'fields' => array_map(static fn(Field $field): array => $field->toArray(), $this->fields),
            'languages' => $this->languages,
            'engine' => $this->engine,
        ];
    }

    /**
     * @throws InvalidIndexDefinitionException
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string)($data['name'] ?? ''),
            className: (string)($data['className'] ?? ''),
            fields: array_map(static fn(array $field): Field => Field::fromArray($field), $data['fields'] ?? []),
            languages: array_values($data['languages'] ?? []),
            engine: isset($data['engine']) ? (string)$data['engine'] : null,
        );
    }

    /**
     * Stored as one record with its fields, instead of a record per field.
     */
    public function __serialize(): array
    {
        return $this->toArray();
    }

    /**
     * @throws InvalidIndexDefinitionException
     */
    public function __unserialize(array $data): void
    {
        $definition = self::fromArray($data);
        $this->name = $definition->name;
        $this->className = $definition->className;
        $this->fields = $definition->fields;
        $this->languages = $definition->languages;
        $this->engine = $definition->engine;
    }

    /**
     * Whether both definitions index the same (documents have to be built again otherwise).
     */
    public function equals(Definition $other): bool
    {
        return $this->toArray() === $other->toArray();
    }
}
