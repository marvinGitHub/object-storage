<?php

namespace melia\ObjectStorage\Search\Loupe;

use Loupe\Loupe\Exception\InvalidDocumentException;
use Loupe\Loupe\Loupe;
use Loupe\Loupe\SearchParameters;
use Loupe\Loupe\SearchResult as LoupeSearchResult;
use melia\ObjectStorage\Search\Engine\EngineIndexInterface;
use melia\ObjectStorage\Search\Exception\SearchException;
use melia\ObjectStorage\Search\Exception\UnsupportedQueryException;
use melia\ObjectStorage\Search\Field;
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
            return array_map(static fn(array $hit): string => (string)$hit['uuid'], $this->getLoupe()->search($parameters)->getHits());
        } catch (Throwable $e) {
            throw new SearchException(message: 'Unable to find expired documents: ' . $e->getMessage(), previous: $e);
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
            $result = $this->getLoupe()->search($parameters);
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

    private function translate(Request $request): SearchParameters
    {
        $parameters = SearchParameters::create()
            ->withQuery($request->query)
            ->withHitsPerPage(max(1, $request->hitsPerPage))
            ->withPage($request->page)
            ->withShowRankingScore('' !== $request->query);

        if (null !== $request->filter && '' !== trim($request->filter)) {
            $parameters = $parameters->withFilter($request->filter);
        }

        // without a sort, Loupe sorts by relevance
        if ([] !== $request->sort) {
            $parameters = $parameters->withSort($request->sort);
        }

        if ([] !== $request->facets) {
            $parameters = $parameters->withFacets($request->facets);
        }

        $searchable = $this->definition->fieldNames(static fn(Field $field): bool => $field->searchable);
        if ($request->highlight && '' !== $request->query && [] !== $searchable) {
            $parameters = $parameters->withAttributesToHighlight($searchable, $request->highlightStartTag, $request->highlightEndTag);
        }

        return $parameters;
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
