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
