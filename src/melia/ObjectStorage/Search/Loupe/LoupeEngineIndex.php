<?php

namespace melia\ObjectStorage\Search\Loupe;

use DateTimeImmutable;
use DateTimeZone;
use melia\ObjectStorage\Serialization\DateTimeValue;
use Loupe\Loupe\BrowseParameters;
use Loupe\Loupe\Exception\InvalidDocumentException;
use Loupe\Loupe\Internal\Filter\Lexer;
use Loupe\Loupe\Loupe;
use Loupe\Loupe\SearchParameters;
use Loupe\Loupe\SearchResult as LoupeSearchResult;
use melia\ObjectStorage\Search\DocumentBuilder;
use melia\ObjectStorage\Search\Engine\EngineIndexInterface;
use melia\ObjectStorage\Search\Exception\SearchException;
use melia\ObjectStorage\Search\Exception\UnsupportedQueryException;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Hit;
use melia\ObjectStorage\Search\Request;
use melia\ObjectStorage\Search\Result;
use Throwable;

/**
 * The documents of a generation of an index in Loupe.
 */
final class LoupeEngineIndex implements EngineIndexInterface
{
    /** attributes Loupe adds to hits */
    private const array HIT_ATTRIBUTES = ['_formatted', '_rankingScore', '_matchesPosition'];

    /** the operators of patterns, which Loupe's filters lack, see expandPatterns() */
    public const array PATTERN_OPERATORS = ['LIKE', 'CONTAINS'];

    /** the most distinct values of a field a pattern is matched against */
    public const int MAX_PATTERN_VALUES = 10000;

    /** the documents read at once for the values of a field */
    private const int BROWSE_BATCH = 1000;

    public function __construct(
        private ?Loupe                           $loupe,
        private readonly Definition         $definition,
    )
    {
    }

    /**
     * The Loupe instance, e.g. for features beyond the interface.
     */
    public function getLoupe(): Loupe
    {
        return $this->loupe ?? throw new SearchException(sprintf('Loupe index "%s" is closed.', $this->definition->name));
    }

    public function upsert(array $documents): array
    {
        if ([] === $documents) {
            return [];
        }

        $loupe = $this->getLoupe();

        try {
            $loupe->addDocuments(array_values($documents));
            return [];
        } catch (InvalidDocumentException) {
            // find the documents Loupe rejects (e.g. a type that doesn't match the other documents)
        } catch (Throwable $e) {
            throw new SearchException(message: 'Unable to index documents: ' . $e->getMessage(), previous: $e);
        }

        $failures = [];
        foreach ($documents as $uuid => $document) {
            try {
                $loupe->addDocument($document);
            } catch (Throwable $e) {
                $failures[(string)$uuid] = $e->getMessage();
            }
        }
        return $failures;
    }

    public function delete(array $uuids): void
    {
        if ([] === $uuids) {
            return;
        }

        try {
            $this->getLoupe()->deleteDocuments(array_values($uuids));
        } catch (Throwable $e) {
            throw new SearchException(message: 'Unable to remove documents: ' . $e->getMessage(), previous: $e);
        }
    }

    public function count(): int
    {
        return $this->getLoupe()->countDocuments();
    }

    public function expired(float $before, int $limit): array
    {
        $parameters = SearchParameters::create()
            ->withFilter(sprintf('expiresAt <= %F', $before))
            ->withAttributesToRetrieve(['uuid'])
            ->withLimit(max(1, $limit));

        try {
            return array_map(static fn(array $hit): string => (string)$hit['uuid'], $this->loupeSearch($parameters)->getHits());
        } catch (Throwable $e) {
            throw new SearchException(message: 'Unable to find expired documents: ' . $e->getMessage(), previous: $e);
        }
    }

    public function referencing(array $uuids, int $limit, string $prefix = ''): array
    {
        if ([] === $uuids) {
            return [];
        }

        $quote = static fn(string $value): string => "'" . str_replace("'", "''", $value) . "'";
        $filter = sprintf('%s IN (%s)', DocumentBuilder::ATTRIBUTE_REFERENCED, implode(', ', array_map($quote, array_values($uuids))));
        if ('' !== $prefix) {
            // "~" sorts after all characters of UUIDs
            $filter .= sprintf(' AND uuid >= %s AND uuid < %s', $quote($prefix), $quote($prefix . '~'));
        }

        // Loupe returns at most maxTotalHits documents per search
        $parameters = SearchParameters::create()
            ->withFilter($filter)
            ->withAttributesToRetrieve(['uuid'])
            ->withLimit(max(1, min($limit, $this->getLoupe()->getConfiguration()->getMaxTotalHits())));

        try {
            return array_map(static fn(array $hit): string => (string)$hit['uuid'], $this->loupeSearch($parameters)->getHits());
        } catch (Throwable $e) {
            throw new SearchException(message: 'Unable to find the documents referencing objects: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * @param Request|SearchParameters $request
     */
    public function search(object $request): Result
    {
        $highlightTags = null;
        // Loupe computes the relevance only when sorting by it (the default), and reports 0 otherwise
        $hasScore = true;

        if ($request instanceof Request) {
            $highlightTags = [$request->highlightStartTag, $request->highlightEndTag];
            $hasScore = [] === $request->sort || in_array('_relevance:desc', $request->sort, true) || in_array('_relevance:asc', $request->sort, true);
            $parameters = $this->translate($request);
        } elseif ($request instanceof SearchParameters) {
            $parameters = $request;
        } else {
            throw new UnsupportedQueryException(sprintf('Loupe accepts %s or %s, not %s.', Request::class, SearchParameters::class, $request::class));
        }

        try {
            $result = $this->loupeSearch($parameters);
        } catch (Throwable $e) {
            // e.g. a syntax error in the filter
            throw new SearchException(message: $e->getMessage(), previous: $e);
        }

        return $this->convert($result, $highlightTags, $hasScore);
    }

    public function close(): void
    {
        $this->loupe = null;
        // Loupe keeps its database connections until the instance is collected
        gc_collect_cycles();
    }

    /**
     * Searches Loupe. Its filter parser keeps the parentheses opened by a filter it failed on (loupe/loupe 1.1),
     * so that every filter after it fails ("Expected a closing parenthesis"): they are forgotten after a failure.
     */
    private function loupeSearch(SearchParameters $parameters): LoupeSearchResult
    {
        try {
            return $this->getLoupe()->search($parameters);
        } catch (Throwable $e) {
            $this->resetFilterParser();
            throw $e;
        }
    }

    private function resetFilterParser(): void
    {
        try {
            // private properties of Loupe; if they change, nothing is reset
            $engine = (fn() => $this->engine ?? null)->call($this->getLoupe());
            $parser = null === $engine ? null : (fn() => $this->filterParser ?? null)->call($engine);
            if (null !== $parser) {
                (function (): void {
                    while (isset($this->groups) && !$this->groups->isEmpty()) {
                        $this->groups->pop();
                    }
                })->call($parser);
            }
        } catch (Throwable) {
            // nothing to reset
        }
    }

    private function translate(Request $request): SearchParameters
    {
        $parameters = SearchParameters::create()
            ->withQuery($request->query)
            ->withHitsPerPage(max(1, $request->hitsPerPage))
            ->withPage($request->page)
            ->withShowRankingScore('' !== $request->query);

        if (null !== $request->filter && '' !== trim($request->filter)) {
            // NOT first: it turns LIKE into NOT LIKE and the like, which become IN conditions then; dates as
            // numbers; negative numbers last, also those NOT, the patterns and the dates write
            $dateFields = $this->definition->fieldNames(static fn(Field $field): bool => in_array($field->type, [FieldType::DATE, FieldType::DATE_ARRAY], true));
            $parameters = $parameters->withFilter(FilterNumbers::rewrite(FilterDates::rewrite($this->expandPatterns(FilterNegation::rewrite($request->filter)), $dateFields)));
        }

        // without a sort, Loupe sorts by relevance
        if ([] !== $request->sort) {
            $parameters = $parameters->withSort($request->sort);
        }

        if ([] !== $request->facets) {
            $parameters = $parameters->withFacets($request->facets);
        }

        $searchable = $this->definition->fieldNames(static fn(Field $field): bool => $field->searchable);
        if ([] !== $request->searchOn) {
            $parameters = $parameters->withAttributesToSearchOn($request->searchOn);
            $searchable = array_values(array_intersect($searchable, $request->searchOn));
        }
        if ($request->highlight && '' !== $request->query && [] !== $searchable) {
            $parameters = $parameters->withAttributesToHighlight($searchable, $request->highlightStartTag, $request->highlightEndTag);
        }

        return $parameters;
    }

    /**
     * Patterns, which Loupe's filters lack: "name LIKE 'Ho%'" (% any characters, _ one character, \% and \_ the
     * characters themselves) and "name CONTAINS 'om'" (the same as LIKE '%om%'), each also with NOT, ignoring case.
     * Each becomes an IN condition with the distinct values of the field matching the pattern, read as the values
     * of a facet: for filterable string fields and lists of strings with at most MAX_PATTERN_VALUES values.
     *
     * @throws SearchException
     */
    private function expandPatterns(string $filter): string
    {
        $lexer = new Lexer();
        $lexer->setInput($filter);
        $lexer->moveNext();
        $tokens = [];
        while (null !== $lexer->lookahead) {
            $lexer->moveNext();
            $tokens[] = $lexer->token;
        }

        $replacements = [];
        $values = [];
        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $attribute = $tokens[$i];
            if (Lexer::T_ATTRIBUTE_NAME !== $attribute->type || in_array($attribute->value, self::PATTERN_OPERATORS, true)) {
                continue;
            }
            $j = $i + 1;
            $negated = Lexer::T_NOT === ($tokens[$j]->type ?? null);
            if ($negated) {
                $j++;
            }
            $operator = $tokens[$j] ?? null;
            if (null === $operator || Lexer::T_ATTRIBUTE_NAME !== $operator->type || !in_array($operator->value, self::PATTERN_OPERATORS, true)) {
                continue;
            }
            $literal = $tokens[$j + 1] ?? null;
            if (null === $literal || Lexer::T_STRING !== $literal->type) {
                throw new SearchException(sprintf('%s needs a quoted value, e.g. %s %s \'text\'.', $operator->value, $attribute->value, $operator->value));
            }

            $name = (string)$attribute->value;
            $regex = 'CONTAINS' === $operator->value
                ? '/' . preg_quote((string)$literal->value, '/') . '/iu'
                : static::likeToRegex((string)$literal->value);
            $values[$name] ??= $this->distinctValues($name);
            $matching = array_values(array_filter($values[$name], static fn(string $value): bool => 1 === preg_match($regex, $value)));
            // the empty string is no value of a facet; lists of strings don't keep it
            $empty = 'uuid' !== $name && FieldType::STRING === $this->fieldOf($name)->type && 1 === preg_match($regex, '');

            // negated: the documents not matching, those without a value too (NOT IN and != are true for them)
            $parts = [];
            if ([] !== $matching) {
                $parts[] = sprintf('%s %sIN (%s)', $name, $negated ? 'NOT ' : '', implode(', ', array_map(
                    static fn(string $value): string => "'" . str_replace("'", "''", $value) . "'",
                    $matching
                )));
            }
            if ($empty) {
                $parts[] = $negated ? "$name != ''" : "$name = ''";
            }
            $condition = [] === $parts
                // nothing matches: a condition that is always false (or true, negated)
                ? ($negated ? "uuid != ''" : "uuid = ''")
                : implode($negated ? ' AND ' : ' OR ', $parts);
            $replacements[] = [$attribute->position, static::endOfString($filter, $literal->position), '(' . $condition . ')'];
            $i = $j + 1;
        }

        foreach (array_reverse($replacements) as [$start, $end, $condition]) {
            $filter = substr_replace($filter, $condition, $start, $end - $start);
        }
        return $filter;
    }

    private function fieldOf(string $name): ?Field
    {
        foreach ($this->definition->fields as $candidate) {
            if ($candidate->name === $name) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * The distinct values of a field (of lists: of their elements), not empty, read from the documents: Loupe's
     * facets split values at commas (loupe/loupe 1.1).
     *
     * @return string[]
     * @throws SearchException
     */
    private function distinctValues(string $name): array
    {
        $field = $this->fieldOf($name);
        if ('uuid' !== $name) {
            if (null === $field || !$field->filterable) {
                throw new SearchException(sprintf('LIKE and CONTAINS need a filterable field, "%s" isn\'t one.', $name));
            }
            if (!in_array($field->type, [FieldType::STRING, FieldType::STRING_ARRAY], true)) {
                throw new SearchException(sprintf('LIKE and CONTAINS need a field of strings, "%s" is of the type %s.', $name, $field->type->value));
            }
        }

        $values = [];
        $offset = 0;
        try {
            do {
                // browsing isn't limited to the maximum of hits of a search
                $hits = $this->getLoupe()->browse(BrowseParameters::create()
                    ->withAttributesToRetrieve([$name])
                    ->withLimit(self::BROWSE_BATCH)
                    ->withOffset($offset)
                )->getHits();
                foreach ($hits as $hit) {
                    foreach ((array)($hit[$name] ?? []) as $value) {
                        if (is_string($value) && '' !== $value) {
                            $values[$value] = true;
                        }
                    }
                }
                if (count($values) > self::MAX_PATTERN_VALUES) {
                    throw new SearchException(sprintf('"%s" has more than %d distinct values: too many for LIKE and CONTAINS, search the full text instead.', $name, self::MAX_PATTERN_VALUES));
                }
                $offset += self::BROWSE_BATCH;
            } while (self::BROWSE_BATCH === count($hits));
        } catch (SearchException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->resetFilterParser();
            throw new SearchException(message: sprintf('Unable to read the values of "%s": %s', $name, $e->getMessage()), previous: $e);
        }

        // keys that are numbers are integers
        return array_map('strval', array_keys($values));
    }

    /**
     * A LIKE pattern as a regular expression matching whole values, ignoring case.
     */
    private static function likeToRegex(string $pattern): string
    {
        $regex = '';
        $characters = mb_str_split($pattern);
        for ($i = 0, $count = count($characters); $i < $count; $i++) {
            $character = $characters[$i];
            if ('\\' === $character && $i + 1 < $count) {
                $regex .= preg_quote($characters[++$i], '/');
            } else {
                $regex .= match ($character) {
                    '%' => '.*',
                    '_' => '.',
                    default => preg_quote($character, '/'),
                };
            }
        }
        return '/^' . $regex . '$/isu';
    }

    /**
     * The position after a quoted string of a filter starting at $start, with '' for a quote inside.
     */
    private static function endOfString(string $filter, int $start): int
    {
        $length = strlen($filter);
        for ($i = $start + 1; $i < $length; $i++) {
            if ("'" === $filter[$i]) {
                if ("'" === ($filter[$i + 1] ?? null)) {
                    $i++;
                    continue;
                }
                return $i + 1;
            }
        }
        return $length;
    }

    /**
     * The values of date fields of a hit as their point in time in UTC as stored ("2026-11-09T09:00:00.000000Z"),
     * not the numbers they are indexed as.
     */
    private function withDates(array $document): array
    {
        foreach ($this->definition->fields as $field) {
            if (!array_key_exists($field->name, $document) || !in_array($field->type, [FieldType::DATE, FieldType::DATE_ARRAY], true)) {
                continue;
            }
            $toText = static function (mixed $seconds): mixed {
                if (!is_int($seconds) && !is_float($seconds)) {
                    return $seconds;
                }
                $date = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $seconds), new DateTimeZone('UTC'));
                return false === $date ? $seconds : DateTimeValue::utc($date);
            };
            $value = $document[$field->name];
            $document[$field->name] = is_array($value) ? array_map($toText, $value) : $toText($value);
        }
        return $document;
    }

    /**
     * @param array{0: string, 1: string}|null $highlightTags
     */
    private function convert(LoupeSearchResult $result, ?array $highlightTags, bool $hasScore): Result
    {
        $hits = [];
        foreach ($result->getHits() as $hit) {
            $formatted = $hit['_formatted'] ?? [];
            $score = $hasScore && isset($hit['_rankingScore']) ? (float)$hit['_rankingScore'] : null;
            $document = array_diff_key($hit, array_flip(self::HIT_ATTRIBUTES));
            $uuid = (string)$document['uuid'];
            unset($document['uuid']);
            $document = $this->withDates($document);

            // only fields with a match: Loupe returns all highlighted fields, marked or not
            $highlighted = [];
            if (null !== $highlightTags) {
                foreach ($formatted as $attribute => $value) {
                    if (is_string($value) && str_contains($value, $highlightTags[0])) {
                        $highlighted[$attribute] = $value;
                    }
                }
            }

            $hits[] = new Hit($uuid, $document, $highlighted, $score);
        }

        return new Result(
            hits: $hits,
            totalHits: $result->getTotalHits(),
            page: $result->getPage(),
            hitsPerPage: $result->getHitsPerPage(),
            totalPages: $result->getTotalPages(),
            processingTimeMs: $result->getProcessingTimeMs(),
            facets: $result->getFacetDistribution(),
            raw: $result,
        );
    }
}
