<?php

namespace Tests\melia\ObjectStorage\Search;

use melia\ObjectStorage\Search\Engine\EngineIndexInterface;
use melia\ObjectStorage\Search\Engine\EngineInterface;
use melia\ObjectStorage\Search\Exception\InvalidIndexDefinitionException;
use melia\ObjectStorage\Search\Index\Definition;

/**
 * A minimal engine for the tests: documents in a JSON file, full text as substring match, filters as
 * "field = value". It shows that the index manager doesn't depend on Loupe.
 */
final class ArrayEngine implements EngineInterface
{
    /** @var string[] directories of the removed generations */
    public array $removed = [];

    /**
     * @param string $name The name of the engine.
     * @param (callable(array): ?string)|null $reject Returns an error message for documents to reject.
     */
    public function __construct(
        private readonly string $name = 'array',
        private readonly mixed  $reject = null,
    )
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function validate(Definition $definition): void
    {
        foreach ($definition->fields as $field) {
            if (str_starts_with($field->name, 'unsupported')) {
                throw new InvalidIndexDefinitionException(sprintf('The array engine doesn\'t accept the field "%s".', $field->name));
            }
        }
    }

    public function filterAttributes(string $filter): array
    {
        // the only filter of this engine: "field = 'value'"
        if (!preg_match('/^([\w.]+)\s*=\s*\'.*\'$/', trim($filter), $match)) {
            throw new \melia\ObjectStorage\Search\Exception\SearchException('Invalid filter: ' . $filter);
        }
        return [$match[1]];
    }

    public function renameFilterAttributes(string $filter, array $names): string
    {
        $attribute = $this->filterAttributes($filter)[0];
        return isset($names[$attribute]) ? preg_replace('/^\s*[\w.]+/', $names[$attribute], $filter) : $filter;
    }

    public function open(string $directory, Definition $definition): EngineIndexInterface
    {
        return new ArrayEngineIndex($directory . DIRECTORY_SEPARATOR . 'documents.json', $definition, $this->reject);
    }

    public function remove(string $directory, Definition $definition): void
    {
        $this->removed[] = $directory;
    }
}
