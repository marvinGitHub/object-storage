<?php

namespace melia\ObjectStorage\Storage;

use melia\ObjectStorage\Exception\Exception;
use melia\ObjectStorage\Exception\ObjectLoadingFailureException;
use melia\ObjectStorage\Exception\ObjectMatchingFailureException;
use melia\ObjectStorage\Logger\LoggerInterface;
use melia\ObjectStorage\UUID\Exception\GenerationFailureException;
use melia\ObjectStorage\UUID\Generator;
use melia\ObjectStorage\UUID\Validator;
use Throwable;

/**
 * An abstract implementation providing foundational functionality for storage operations.
 * Implements the methods necessary for interaction with a provided storage interface
 * and manages logging capabilities to track operations and exceptions.
 */
abstract class StorageAbstract implements StorageInterface, StorageAssumeInterface
{
    private ?LoggerInterface $logger = null;

    /**
     * @throws GenerationFailureException
     */
    public function getNextAvailableUuid(): string
    {
        do {
            $uuid = Generator::generate();
        } while ($this->exists($uuid));
        return $uuid;
    }

    /**
     * Assumes objects from the given storage by loading and storing them.
     * Logs any exceptions that occur during the process.
     *
     * @param StorageInterface $storage The storage interface containing the objects to process.
     * @return void
     */
    public function assume(StorageInterface $storage): void
    {
        foreach ($storage->list() as $uuid) {
            try {
                $this->store($storage->load($uuid));
            } catch (Throwable $e) {
                $this->getLogger()?->log(new Exception(sprintf('Error occurred while assuming object with uuid %s', $uuid), Exception::CODE_FAILURE_OBJECT_ASSUMPTION, $e));
            }
        }
    }

    /**
     * Retrieves the logger instance.
     *
     * @return LoggerInterface|null The logger instance if available, or null if no logger is set.
     */
    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Sets the logger instance for the class.
     *
     * @param LoggerInterface $logger The logger instance to be used for logging messages.
     * @return void
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Counts the number of objects matching the specified class name.
     *
     * @param string|null $className An optional class name used to filter objects.
     * @return int Returns the count of objects that match the specified class name.
     */
    public function count(?string $className = null): int
    {
        return count($this->match(function (object $object) {
            return true;
        }, $className));
    }

    protected function getSelection(?array $subSet = null): iterable
    {
        return $subSet ? array_unique(array_filter([...array_values($subSet), ...array_keys($subSet)], function ($uuid) {
            return is_string($uuid) && Validator::validate($uuid);
        })) : $this->list();
    }

    /**
     * Searches for objects that match a given condition defined by the matcher function.
     * Optionally filters results by class name and limits the number of matches.
     *
     * @param callable $matcher A callable function to evaluate each object. The callable should return true for a match.
     * @param string|null $className An optional class name to filter objects by their type. Defaults to null, meaning no class filtering is applied.
     * @param int $limit The maximum number of matches to return. Defaults to 0, meaning no limit.
     * @param array|null $subSet An optional array either of UUIDs to search within or result of previous match() call. Defaults to null, meaning search all objects.
     * @return array An associative array where the keys are UUIDs of the matching objects and the values are the objects themselves.
     */
    public function match(callable $matcher, ?string $className = null, int $limit = 0, ?array $subSet = null): array
    {
        $results = [];

        foreach ($this->getSelection($subSet) as $uuid) {
            if ($limit > 0 && count($results) >= $limit) {
                break;
            }

            try {
                /* dont use get_class() on $object since this would return the anonymous class name if the object is an anonymous class */
                if ($className !== null && $this->getClassName($uuid) !== $className) {
                    continue;
                }

                $object = $this->load($uuid);

                if ($object === null) {
                    continue;
                }
            } catch (Throwable $e) {
                $this->getLogger()?->log(new ObjectLoadingFailureException(sprintf('Error while loading object %s', $uuid), Exception::CODE_FAILURE_OBJECT_LOADING, $e));
                continue;
            }

            try {
                if ($matcher($object)) {
                    $results[$uuid] = $object;
                }
            } catch (Throwable $e) {
                $this->getLogger()?->log(new ObjectMatchingFailureException(sprintf('Error while matching object %s', $uuid), Exception::CODE_FAILURE_OBJECT_MATCH, $e));
                continue;
            }
        }

        return $results;
    }
}