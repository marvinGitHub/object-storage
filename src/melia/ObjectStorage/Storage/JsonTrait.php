<?php

namespace melia\ObjectStorage\Storage;

use melia\ObjectStorage\Exception\CorruptedFileException;
use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\Exception\SafeModeActivationFailedException;
use melia\ObjectStorage\Exception\SerializationFailureException;
use melia\ObjectStorage\File\JsonFiles;
use melia\ObjectStorage\Strategy\StrategyInterface;
use Random\RandomException;

/**
 * The JSON files of the storage (metadata, the registry of class names), delegated to the component JsonStore:
 * through the files of the storage (JsonFiles on top of getFiles()), a file that holds no valid JSON enables safe
 * mode.
 *
 * Part of ObjectStorage, kept apart for readability.
 *
 * @mixin \melia\ObjectStorage\ObjectStorage
 */
trait JsonTrait
{
    /**
     * The decoded JSON of a file of the storage, see JsonStore::read().
     *
     * @return array|null Null if there is no such file.
     * @throws IOException If the file exists but can't be read.
     * @throws CorruptedFileException If it holds no valid JSON (safe mode is enabled).
     * @throws SafeModeActivationFailedException If safe mode can't be enabled.
     */
    protected function readJson(string $path): ?array
    {
        return $this->jsonStore()->read($path);
    }

    /**
     * Writes a value as JSON into a file of the storage, atomically; a missing directory is created.
     *
     * @throws SerializationFailureException If the value can't be encoded; nothing is written.
     * @throws IOException If the file can't be written; its previous contents are kept.
     * @throws RandomException If no random bytes can be generated for the name of the staging file.
     */
    protected function writeJson(string $path, mixed $value): void
    {
        $this->jsonStore()->write($path, $value);
    }

    /**
     * The JSON files of the storage, with its files, the maximum depth of its strategy, its logger, event
     * dispatcher and state handler as they are now.
     */
    protected function jsonStore(): JsonStore
    {
        $files = $this->getFiles();
        $maxDepth = $this->getStrategy()?->getMaxDepth() ?? StrategyInterface::DEFAULT_MAX_DEPTH;
        $logger = $this->getLogger();
        $dispatcher = $this->getEventDispatcher();
        $stateHandler = $this->getStateHandler();

        return $this->component(
            JsonStore::class,
            [$files, $maxDepth, $logger, $dispatcher, $stateHandler],
            static fn(): JsonStore => new JsonStore(new JsonFiles($files, $maxDepth), $logger, $dispatcher, $stateHandler),
        );
    }
}
