<?php

namespace melia\ObjectStorage\Search\Index;

use Generator;
use melia\ObjectStorage\Search\Exception\NoSuitableIndexException;
use melia\ObjectStorage\Search\Request;
use melia\ObjectStorage\Search\Result;
use melia\ObjectStorage\Search\Engine\EngineInterface;
use melia\ObjectStorage\Search\Exception\EngineNotFoundException;
use melia\ObjectStorage\Search\Exception\IndexAlreadyExistsException;
use melia\ObjectStorage\Search\Exception\IndexBuildInProgressException;
use melia\ObjectStorage\Search\Exception\IndexNotFoundException;
use melia\ObjectStorage\Search\Exception\SearchException;

/**
 * Manages the search indexes of a storage, whatever their engines. Index names are unique per storage, across
 * engines, so an application finds an index by its name only.
 */
interface ManagerInterface
{
    /**
     * @return array<string, EngineInterface> name => engine, the first one is the default
     */
    public function getEngines(): array;

    /**
     * @throws EngineNotFoundException
     */
    public function getEngine(?string $name = null): EngineInterface;

    /**
     * @return array<string, IndexInterface> name => index, sorted by name
     * @throws SearchException
     */
    public function list(): array;

    /**
     * @throws SearchException
     */
    public function has(string $name): bool;

    /**
     * @throws IndexNotFoundException
     * @throws SearchException
     */
    public function get(string $name): IndexInterface;

    /**
     * Searches the objects of a class: the manager chooses the index, like a database chooses the index of a table,
     * see explain().
     *
     * @throws NoSuitableIndexException If no index of the class can answer the search.
     * @throws SearchException
     */
    public function search(string $className, Request $request): Result;

    /**
     * Searches the objects of a class and loads the found ones, see search() and IndexInterface::searchObjects().
     *
     * @return Generator<string, object> UUID => object
     * @throws NoSuitableIndexException If no index of the class can answer the search.
     * @throws SearchException
     */
    public function searchObjects(string $className, Request $request): Generator;

    /**
     * Which index of a class answers a search, and why the others don't.
     *
     * An index can answer it if it has been built, and the definition it was built with has: searchable fields
     * for a full-text query (all those of searchOn), filterable fields for the filter and the facets, sortable
     * fields for the sort. Of those, indexes built with their current definition come first; for a full-text query
     * the one searching the most fields (so the query covers as much as it can); then the smallest one; then the
     * name.
     *
     * @throws SearchException
     */
    public function explain(string $className, Request $request): Plan;

    /**
     * Creates an index with the engine of the definition (the default engine if it names none); it is empty
     * until it is built.
     *
     * @throws IndexAlreadyExistsException If an index of the name exists, of whatever engine.
     * @throws EngineNotFoundException
     * @throws SearchException
     */
    public function create(Definition $definition): IndexInterface;

    /**
     * Replaces the definition of an index; it is outdated until it is built again. The engine can't be changed.
     *
     * @throws IndexNotFoundException
     * @throws IndexBuildInProgressException
     * @throws SearchException
     */
    public function update(Definition $definition): IndexInterface;

    /**
     * Removes an index with all its data.
     *
     * @throws IndexNotFoundException
     * @throws SearchException
     */
    public function drop(string $name): void;

    /**
     * Applies the recorded changes to all indexes.
     *
     * @return array<string, int> name => number of applied changes
     * @throws SearchException
     */
    public function sync(bool $wait = true): array;

    /**
     * Connects the indexes to the storage, so changes of indexed objects are recorded and, with auto sync,
     * applied right after each store and delete.
     */
    public function attach(bool $autoSync = true): void;

    public function detach(): void;

    /**
     * Records a change of an object written without the storage, and applies it with auto sync.
     *
     * @param string $uuid The UUID of the changed (or deleted) object.
     * @param string|null $className The class of the object; null to read it from its metadata, or if it was deleted.
     * @param string[]|null $indexes The names of the indexes to update; null for all indexes of the class. Indexes
     *                               of other classes are ignored, so are unknown names.
     * @return string[] The names of the updated indexes.
     * @throws SearchException
     */
    public function notifyChanged(string $uuid, ?string $className = null, ?array $indexes = null): array;
}
