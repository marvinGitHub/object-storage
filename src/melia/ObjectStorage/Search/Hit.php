<?php

namespace melia\ObjectStorage\Search;

/**
 * A found document.
 */
final readonly class Hit
{
    /**
     * @param string $uuid The UUID of the object.
     * @param array<string, mixed> $document The indexed fields (and "revision"), as the engine returns them.
     * @param array<string, string> $highlighted Searchable fields with matches, with the matches marked by the tags
     *                                           of the request. The text is not escaped.
     * @param float|null $score The relevance (0 to 1) for full-text queries, if the engine provides it.
     */
    public function __construct(
        public string $uuid,
        public array  $document,
        public array  $highlighted = [],
        public ?float $score = null,
    )
    {
    }
}
