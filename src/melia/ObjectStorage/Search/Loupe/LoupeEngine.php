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
        return array_values(array_unique(array_column(static::attributesOf($filter), 0)));
    }

    public function renameFilterAttributes(string $filter, array $names): string
    {
        // from the end, so the positions of the attributes before stay valid
        foreach (array_reverse(static::attributesOf($filter)) as [$attribute, $position]) {
            if (isset($names[$attribute])) {
                $filter = substr_replace($filter, $names[$attribute], $position, strlen($attribute));
            }
        }
        return $filter;
    }

    /**
     * The attributes of a filter with their positions, told apart from keywords and quoted strings by Loupe's own
     * filter lexer. A path ("friend.name") is one attribute: the lexer returns its names and dots as tokens.
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function attributesOf(string $filter): array
    {
        $lexer = new Lexer();
        $lexer->setInput($filter);
        $lexer->moveNext();

        $attributes = [];
        $last = null; // the attribute being read: [name, position, end], ending with a dot while a path continues
        while (null !== $lexer->lookahead) {
            $lexer->moveNext();
            $token = $lexer->token;
            $value = (string)$token?->value;
            $position = (int)$token?->position;

            // LIKE and CONTAINS are operators of this engine, which the lexer of Loupe reads as attribute names
            if (Lexer::T_ATTRIBUTE_NAME === $token?->type && !in_array($value, LoupeEngineIndex::PATTERN_OPERATORS, true)) {
                if (null !== $last && str_ends_with($last[0], '.') && $last[2] === $position) {
                    $last[0] .= $value;
                    $last[2] = $position + strlen($value);
                } else {
                    if (null !== $last) {
                        $attributes[] = [rtrim($last[0], '.'), $last[1]];
                    }
                    $last = [$value, $position, $position + strlen($value)];
                }
            } elseif ('.' === $value && null !== $last && $last[2] === $position && !str_ends_with($last[0], '.')) {
                $last[0] .= '.';
                $last[2] = $position + 1;
            } elseif (null !== $last) {
                $attributes[] = [rtrim($last[0], '.'), $last[1]];
                $last = null;
            }
        }
        if (null !== $last) {
            $attributes[] = [rtrim($last[0], '.'), $last[1]];
        }

        return $attributes;
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
