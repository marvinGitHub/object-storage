<?php

namespace melia\ObjectStorage\Search;

use InvalidArgumentException;

/**
 * A search independent of the engine. Only the filter is written in the language of the engine of the index
 * (e.g. "price < 10 AND tags IN ('a')" for Loupe); everything else is translated by the engine.
 *
 * Engines also accept their own query objects for features beyond this request, see IndexInterface::search().
 */
final readonly class Request
{
    /**
     * @param string $query Full-text query over the searchable fields; empty to match all documents.
     * @param string|null $filter A filter in the language of the engine, over the filterable fields.
     * @param string[] $sort Sortable fields with direction, e.g. ['price:asc', 'name:desc']; empty for relevance.
     * @param string[] $facets Filterable fields to count the values of among all hits.
     * @param int $page The page of hits, starting with 1.
     * @param int $hitsPerPage The number of hits per page.
     * @param bool $highlight Whether to return the searchable fields with the matches marked, see Hit.
     * @param string $highlightStartTag Inserted before each match.
     * @param string $highlightEndTag Inserted after each match.
     * @param string[] $searchOn The searchable fields the full-text query covers; empty for all searchable fields
     *                           of the index. When an index is chosen for a class, it must have these fields
     *                           searchable, so the result doesn't depend on the choice.
     */
    public function __construct(
        public string  $query = '',
        public ?string $filter = null,
        public array   $sort = [],
        public array   $facets = [],
        public int     $page = 1,
        public int     $hitsPerPage = 20,
        public bool    $highlight = false,
        public string  $highlightStartTag = '<mark>',
        public string  $highlightEndTag = '</mark>',
        public array   $searchOn = [],
    )
    {
        if ($page < 1) {
            throw new InvalidArgumentException('The page starts with 1.');
        }
        if ($hitsPerPage < 0) {
            throw new InvalidArgumentException('The number of hits per page can\'t be negative.');
        }
        foreach ($searchOn as $field) {
            if (!is_string($field) || '' === $field) {
                throw new InvalidArgumentException('The fields to search on must be field names.');
            }
        }
        foreach ($sort as $criterion) {
            if (!is_string($criterion) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*:(asc|desc)$/', $criterion)) {
                throw new InvalidArgumentException(sprintf('Invalid sort criterion "%s": use "field:asc" or "field:desc".', $criterion));
            }
        }
    }
}
