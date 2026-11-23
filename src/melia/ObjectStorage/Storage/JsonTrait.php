<?php

namespace melia\ObjectStorage\Storage;

use melia\ObjectStorage\Event\Context\IOContext;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\CorruptedFileException;
use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\Exception\SafeModeActivationFailedException;
use melia\ObjectStorage\Exception\SerializationFailureException;
use melia\ObjectStorage\File\JsonFiles;
use melia\ObjectStorage\Strategy\StrategyInterface;
use Random\RandomException;

/**
 * The JSON files of the storage (metadata, the registry of class names): readJson() reads and decodes, writeJson()
 * encodes and writes, through the files of the storage (JsonFiles on top of getFiles()).
 *
 * A file that holds no valid JSON is a damaged file of the storage: safe mode is enabled.
 *
 * Part of ObjectStorage, kept apart for readability.
 *
 * @mixin \melia\ObjectStorage\ObjectStorage
 */
trait JsonTrait
{
    /**
     * The decoded JSON of a file of the storage.
     *
     * @return array|null Null if there is no such file.
     * @throws IOException If the file exists but can't be read (logged, IO_READ_FAILURE dispatched).
     * @throws CorruptedFileException If it holds no valid JSON (JSON_DECODING_FAILURE dispatched, safe mode enabled).
     * @throws SafeModeActivationFailedException If safe mode can't be enabled.
     */
    protected function readJson(string $path): ?array
    {
        try {
            return $this->jsonFiles()->read($path);
        } catch (IOException $e) {
            $this->getLogger()?->log($e);
            $this->getEventDispatcher()?->dispatch(Events::IO_READ_FAILURE, static fn() => new IOContext($path));
            throw $e;
        } catch (CorruptedFileException $e) {
            $this->getLogger()?->log($e);
            $this->getEventDispatcher()?->dispatch(Events::JSON_DECODING_FAILURE, static fn() => new IOContext($path));
            $this->getStateHandler()?->enableSafeMode();
            throw $e;
        }
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
        $this->jsonFiles()->write($path, $value);
    }

    /**
     * Created per use, so files or a strategy set later are used.
     */
    private function jsonFiles(): JsonFiles
    {
        return new JsonFiles($this->getFiles(), $this->getStrategy()?->getMaxDepth() ?? StrategyInterface::DEFAULT_MAX_DEPTH);
    }
}
