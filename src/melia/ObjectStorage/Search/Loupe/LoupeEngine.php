<?php

namespace melia\ObjectStorage\Search\Loupe;

use Closure;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\Internal\Filter\Lexer;
use Loupe\Loupe\LoupeFactory;
use Loupe\Loupe\LoupeFactoryInterface;
use melia\ObjectStorage\Search\DocumentBuilder;
use melia\ObjectStorage\Search\Engine\EngineIndexInterface;
use melia\ObjectStorage\Search\Engine\EngineInterface;
use melia\ObjectStorage\Search\Exception\InvalidIndexDefinitionException;
use melia\ObjectStorage\Search\Exception\SearchException;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\Index\Definition;
use Throwable;

/**
 * Search engine with Loupe (https://github.com/loupe-php/loupe): full-text search with typo tolerance, stemming,
 * filters, sorting and facets on SQLite, in PHP without a server. Requires loupe/loupe, ext-intl and
 * ext-pdo_sqlite, and a local file system.
 *
 * Filters are written in Loupe's language, e.g. "price < 10 AND tags IN ('a', 'b')".
 */
final class LoupeEngine implements EngineInterface
{
    public const string NAME = 'loupe';

    private readonly LoupeFactoryInterface $factory;

    /**
     * @param LoupeFactoryInterface|null $factory Creates the Loupe instances, e.g. to configure them further.
     * @param (Closure(Configuration, Definition): Configuration)|null $configure Adjusts the configuration
     *        of an index beyond its definition, e.g. typo tolerance or stop words.
     */
    public function __construct(
        ?LoupeFactoryInterface    $factory = null,
        private readonly ?Closure $configure = null,
    )
    {
        $this->factory = $factory ?? new LoupeFactory();
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function validate(Definition $definition): void
    {
        foreach ($definition->fields as $field) {
            try {
                Configuration::validateAttributeName($field->name);
            } catch (Throwable $e) {
                throw new InvalidIndexDefinitionException(message: sprintf('Loupe doesn\'t accept the field name "%s".', $field->name), previous: $e);
            }
        }
    }

    public function filterAttributes(string $filter): array
    {
        // the tokens of Loupe's own filter lexer: attribute names are told apart from keywords and quoted strings
        $lexer = new Lexer();
        $lexer->setInput($filter);
        $lexer->moveNext();

        $attributes = [];
        while (null !== $lexer->lookahead) {
            $lexer->moveNext();
            if (Lexer::T_ATTRIBUTE_NAME === $lexer->token?->type) {
                $attributes[(string)$lexer->token->value] = true;
            }
        }

        return array_keys($attributes);
    }

    public function open(string $directory, Definition $definition): EngineIndexInterface
    {
        try {
            $loupe = $this->factory->create($directory, $this->createConfiguration($definition));
        } catch (Throwable $e) {
            throw new SearchException(message: sprintf('Unable to open Loupe index "%s": %s', $definition->name, $e->getMessage()), previous: $e);
        }

        return new LoupeEngineIndex($loupe, $definition);
    }

    public function remove(string $directory, Definition $definition): void
    {
        // the data is a SQLite database in the directory, which the index manager removes
    }

    /**
     * The Loupe configuration of a definition.
     */
    public function createConfiguration(Definition $definition): Configuration
    {
        $configuration = Configuration::create()
            ->withPrimaryKey('uuid')
            ->withSearchableAttributes($definition->fieldNames(static fn(Field $field): bool => $field->searchable))
            // the UUID can always be filtered, e.g. to restrict a search to known objects; the expiration to find
            // expired objects; the referenced objects to find the documents depending on them
            ->withFilterableAttributes(['uuid', 'expiresAt', DocumentBuilder::ATTRIBUTE_REFERENCED, ...$definition->fieldNames(static fn(Field $field): bool => $field->filterable)])
            ->withSortableAttributes($definition->fieldNames(static fn(Field $field): bool => $field->sortable));

        if ([] !== $definition->languages) {
            $configuration = $configuration->withLanguages($definition->languages);
        }

        return null === $this->configure ? $configuration : ($this->configure)($configuration, $definition);
    }
}
