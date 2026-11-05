<?php

namespace melia\ObjectStorage\Search\Engine;

use melia\ObjectStorage\Search\Exception\SearchException;
use melia\ObjectStorage\Search\Exception\UnsupportedQueryException;
use melia\ObjectStorage\Search\Request;
use melia\ObjectStorage\Search\Result;

/**
 * The documents of one generation of an index in an engine.
 *
 * A document has the attributes "uuid" (its identity), "revision", "expiresAt", "referencedUuids" (the UUIDs of the
 * referenced objects it was built from, a list of strings) and one per field of the definition, with values already
 * converted to the types of the fields (or null).
 */
interface EngineIndexInterface
{
    /**
     * Adds documents, or replaces those with the same UUID.
     *
     * @param array<string, array> $documents UUID => document
     * @return array<string, string> UUID => error message, for documents the engine rejected; the others are stored.
     * @throws SearchException If storing failed as a whole.
     */
    public function upsert(array $documents): array;

    /**
     * Removes documents; UUIDs without a document are ignored.
     *
     * @param string[] $uuids
     * @throws SearchException
     */
    public function delete(array $uuids): void;

    /**
     * @throws SearchException
     */
    public function count(): int;

    /**
     * Finds documents of expired objects: those whose attribute "expiresAt" is set and at or before a point in time.
     *
     * @param float $before Seconds since the epoch, usually now.
     * @param int $limit The maximum number of UUIDs.
     * @return string[] UUIDs of the documents.
     * @throws SearchException
     */
    public function expired(float $before, int $limit): array;

    /**
     * Finds the documents built from referenced objects: those whose attribute "referencedUuids" contains one of
     * the UUIDs, and whose UUID starts with a prefix. The index asks again with longer prefixes if the limit is
     * reached, so an engine needn't page through many documents.
     *
     * @param string[] $uuids UUIDs of referenced objects.
     * @param int $limit The maximum number of UUIDs.
     * @param string $prefix The beginning of the UUIDs of the documents, "" for all.
     * @return string[] UUIDs of the documents.
     * @throws SearchException
     */
    public function referencing(array $uuids, int $limit, string $prefix = ''): array;

    /**
     * Searches the documents.
     *
     * @param Request|object $request A Request, or a query object of the engine itself.
     * @throws UnsupportedQueryException If the engine doesn't accept the query object.
     * @throws SearchException If the search failed, e.g. because of an invalid filter.
     */
    public function search(object $request): Result;

    /**
     * Releases resources such as open files or connections.
     */
    public function close(): void;
}
