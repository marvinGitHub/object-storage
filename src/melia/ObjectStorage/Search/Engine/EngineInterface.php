<?php

namespace melia\ObjectStorage\Search\Engine;

use melia\ObjectStorage\Search\Exception\InvalidIndexDefinitionException;
use melia\ObjectStorage\Search\Exception\SearchException;
use melia\ObjectStorage\Search\Index\Definition;

/**
 * A search engine, e.g. Loupe. It only stores and searches documents; everything else (definitions, building,
 * keeping indexes up to date, locking) is done by the index manager, independent of the engine.
 *
 * To connect another engine, implement this interface and EngineIndexInterface, and pass the engine to the
 * Manager.
 */
interface EngineInterface
{
    /**
     * The name of the engine, e.g. "loupe": lowercase letters and digits, starting with a letter. It is stored
     * in the definitions of its indexes and names the directory of their data.
     */
    public function getName(): string;

    /**
     * Checks whether the engine can index a definition, e.g. its field names or languages.
     *
     * @throws InvalidIndexDefinitionException
     */
    public function validate(Definition $definition): void;

    /**
     * The attributes a filter in the language of the engine uses, e.g. ['price', 'tags'] for
     * "price < 10 AND tags IN ('a')". Used to choose an index whose filterable fields cover a filter.
     *
     * @return string[]
     * @throws SearchException If the filter isn't valid in the language of the engine.
     */
    public function filterAttributes(string $filter): array;

    /**
     * Renames attributes in a filter in the language of the engine, e.g. paths into the names of their fields:
     * ['friend.name' => 'friendName'] turns "friend.name = 'Homa'" into "friendName = 'Homa'". Quoted values stay as
     * they are; attributes not in $names too.
     *
     * @param array<string, string> $names Attribute => new name.
     * @throws SearchException If the filter isn't valid in the language of the engine.
     */
    public function renameFilterAttributes(string $filter, array $names): string;

    /**
     * Opens (and creates if needed) the documents of a generation of an index. A generation is built once with
     * one definition; a changed definition is built into a new generation.
     *
     * @param string $directory A directory of its own for the data of the generation; engines that keep their
     *                          data elsewhere (e.g. on a server) derive a unique name from it.
     * @param Definition $definition The definition the generation is built with.
     * @throws SearchException
     */
    public function open(string $directory, Definition $definition): EngineIndexInterface;

    /**
     * Removes the data of a generation, e.g. after a newer one was built or the index was dropped.
     * The local directory is removed by the index manager afterwards.
     *
     * @throws SearchException
     */
    public function remove(string $directory, Definition $definition): void;
}
