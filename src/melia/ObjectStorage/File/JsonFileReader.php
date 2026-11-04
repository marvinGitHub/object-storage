<?php

namespace melia\ObjectStorage\File;

use Closure;
use melia\ObjectStorage\Event\Context\IOContext;
use melia\ObjectStorage\Event\DispatcherInterface;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\SerializationFailureException;
use melia\ObjectStorage\Logger\LoggerInterface;
use Throwable;

/**
 * Reads files and decodes JSON, the way the storage reads its files: a file that can't be read is reported
 * (logged, IO_READ_FAILURE) and read as null; invalid JSON is reported (JSON_DECODING_FAILURE) and throws.
 *
 * What a damaged file means is up to the caller, e.g. the storage enables safe mode (see $onCorruption).
 */
final class JsonFileReader
{
    /** The maximum nesting depth of decoded JSON, unless given. */
    public const int MAX_DEPTH_DEFAULT = 512;

    /**
     * @param ReaderInterface $reader Reads the files.
     * @param int $maxDepth The maximum nesting depth of decoded JSON.
     * @param LoggerInterface|null $logger Receives the errors of reading and decoding.
     * @param DispatcherInterface|null $eventDispatcher Receives IO_READ_FAILURE and JSON_DECODING_FAILURE.
     * @param Closure(string $filename): void|null $onCorruption Called with the file before invalid JSON throws.
     */
    public function __construct(
        private readonly ReaderInterface      $reader = new Reader(),
        private readonly int                  $maxDepth = self::MAX_DEPTH_DEFAULT,
        private readonly ?LoggerInterface     $logger = null,
        private readonly ?DispatcherInterface $eventDispatcher = null,
        private readonly ?Closure             $onCorruption = null,
    )
    {
    }

    /**
     * The contents of a file.
     *
     * @return string|null Null if the file can't be read (reported).
     */
    public function read(string $filename): ?string
    {
        try {
            return $this->reader->read($filename);
        } catch (Throwable $e) {
            $this->logger?->log($e);
            $this->eventDispatcher?->dispatch(Events::IO_READ_FAILURE, static fn() => new IOContext($filename));
            return null;
        }
    }

    /**
     * The decoded JSON of a file, as an array.
     *
     * @return array|null Null if the file can't be read (reported).
     * @throws SerializationFailureException If the file holds no valid JSON object or array.
     */
    public function readJson(string $filename): ?array
    {
        $json = $this->read($filename);
        return null === $json ? null : $this->decode($json, $filename);
    }

    /**
     * Decodes JSON read from a file.
     *
     * @param string $json The JSON.
     * @param string $filename The file it was read from, for the report.
     * @throws SerializationFailureException If it is no valid JSON object or array.
     */
    public function decode(string $json, string $filename): array
    {
        $decoded = $this->tryDecode($json);
        if (null !== $decoded) {
            return $decoded;
        }

        $this->eventDispatcher?->dispatch(Events::JSON_DECODING_FAILURE, static fn() => new IOContext($filename));
        if (null !== $this->onCorruption) {
            ($this->onCorruption)($filename);
        }
        throw new SerializationFailureException('Unable to decode data from file: ' . $filename);
    }

    /**
     * Decodes JSON, without reporting invalid JSON as a damaged file, e.g. to look into data that is verified
     * elsewhere; the error is logged.
     *
     * @return array|null Null if it is no valid JSON object or array.
     */
    public function tryDecode(string $json): ?array
    {
        try {
            $decoded = json_decode($json, true, $this->maxDepth, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->logger?->log($e);
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
