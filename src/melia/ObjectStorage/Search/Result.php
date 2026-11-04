<?php

namespace melia\ObjectStorage\Search;

/**
 * The result of a search, independent of the engine.
 */
final readonly class Result
{
    /**
     * @param Hit[] $hits The hits of the requested page, in order.
     * @param int $totalHits The number of all hits (engines may cap it).
     * @param int $page The page, starting with 1.
     * @param int $hitsPerPage The number of hits per page.
     * @param int $totalPages The number of pages.
     * @param int $processingTimeMs The time the engine took.
     * @param array<string, array<string, int>> $facets Field => value => number of hits, for the requested facets.
     * @param mixed $raw The result of the engine, for features beyond this class.
     */
    public function __construct(
        public array $hits,
        public int   $totalHits,
        public int   $page = 1,
        public int   $hitsPerPage = 20,
        public int   $totalPages = 1,
        public int   $processingTimeMs = 0,
        public array $facets = [],
        public mixed $raw = null,
    )
    {
    }

    /**
     * @return string[] The UUIDs of the hits, in order.
     */
    public function getUuids(): array
    {
        return array_map(static fn(Hit $hit): string => $hit->uuid, $this->hits);
    }
}
