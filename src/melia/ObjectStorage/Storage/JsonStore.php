<?php

namespace melia\ObjectStorage\Storage;

use melia\ObjectStorage\Event\Context\IOContext;
use melia\ObjectStorage\Event\DispatcherInterface;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\CorruptedFileException;
use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\Exception\SafeModeActivationFailedException;
use melia\ObjectStorage\Exception\SerializationFailureException;
use melia\ObjectStorage\File\JsonFiles;
use melia\ObjectStorage\Logger\LoggerInterface;
use melia\ObjectStorage\State\StateHandler;
use Random\RandomException;

/**
 * The JSON files of a storage (metadata, the registry of class names), with the rules of the storage for failures:
 * a file that can't be read is logged and reported, a file that holds no valid JSON is a damaged file of the
 * storage and enables safe mode.
 */
final readonly class JsonStore
{
    public function __construct(
        private JsonFiles            $files,
        private ?LoggerInterface     $logger = null,
        private ?DispatcherInterface $dispatcher = null,
        private ?StateHandler        $stateHandler = null,
    )
    {
    }

    /**
     * The decoded JSON of a file.
     *
     * @return array|null Null if there is no such file.
     * @throws IOException If the file exists but can't be read (logged, IO_READ_FAILURE dispatched).
     * @throws CorruptedFileException If it holds no valid JSON (JSON_DECODING_FAILURE dispatched, safe mode enabled).
     * @throws SafeModeActivationFailedException If safe mode can't be enabled.
     */
    public function read(string $path): ?array
    {
        try {
            return $this->files->read($path);
        } catch (IOException $e) {
            $this->logger?->log($e);
            $this->dispatcher?->dispatch(Events::IO_READ_FAILURE, static fn() => new IOContext($path));
            throw $e;
        } catch (CorruptedFileException $e) {
            $this->logger?->log($e);
            $this->dispatcher?->dispatch(Events::JSON_DECODING_FAILURE, static fn() => new IOContext($path));
            $this->stateHandler?->enableSafeMode();
            throw $e;
        }
    }

    /**
     * Writes a value as JSON into a file, atomically; a missing directory is created.
     *
     * @throws SerializationFailureException If the value can't be encoded; nothing is written.
     * @throws IOException If the file can't be written; its previous contents are kept.
     * @throws RandomException If no random bytes can be generated for the name of the staging file.
     */
    public function write(string $path, mixed $value): void
    {
        $this->files->write($path, $value);
    }
}
