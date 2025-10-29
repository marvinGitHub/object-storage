<?php

namespace melia\ObjectStorage\Storage;

use melia\ObjectStorage\Exception\Exception;
use melia\ObjectStorage\Exception\ObjectLoadingFailureException;
use melia\ObjectStorage\Exception\ObjectMatchingFailureException;
use melia\ObjectStorage\Locking\LockAdapterAwareTrait;
use melia\ObjectStorage\Logger\LoggerAwareTrait;
use melia\ObjectStorage\State\StateHandlerAwareTrait;
use melia\ObjectStorage\UUID\Generator\AwareTrait as GeneratorAwareTrait;
use melia\ObjectStorage\UUID\Validator;
use Throwable;
use Traversable;

/**
 * An abstract implementation providing foundational functionality for storage operations.
 * Implements the methods necessary for interaction with a provided storage interface
 * and manages logging capabilities to track operations and exceptions.
 */
abstract class StorageAbstract implements StorageInterface, StorageAssumeInterface
{
    use LoggerAwareTrait;
    use LockAdapterAwareTrait;
    use StateHandlerAwareTrait;
    use GeneratorAwareTrait;

    public function getNextAvailableUuid(): string
    {
        do {
            $uuid = $this->getGenerator()->generate();
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
     * Counts the number of objects matching the specified class name.
     *
     * @param string|null $className An optional class name used to filter objects.
     * @return int Returns the count of objects that match the specified class name.
     */
    public function count(?string $className = null): int
    {
        return iterator_count($this->match(function (object $object) {
            return true;
        }, $className));
    }

    /**
     * Searches for objects that match a given condition defined by the matcher function.
     * Optionally filters results by class name and limits the number of matches.
     *
     * @param callable $matcher A callable function to evaluate each object. The callable should return true for a match. The first argument is the object to be evaluated.
     * @param string|null $className An optional class name to filter objects by their type. Defaults to null, meaning no class filtering is applied.
     * @param int|null $limit The maximum number of matches to return. Defaults to 0, meaning no limit.
     * @param array|null $subSet An optional array either of UUIDs to search within or result of the previous match () call. Defaults to null, meaning search all objects.
     * @return Traversable A traversable where the keys are UUIDs of the matching objects and the values are the objects themselves.
     */
    public function match(callable $matcher, ?string $className = null, ?int $limit = null, ?array $subSet = null): Traversable
    {
        $count = 0;

        foreach ($this->getSelection($subSet) as $uuid) {
            if (null !== $limit && $limit > 0 && $count >= $limit) {
                break;
            }

            try {
                if ($className !== null && $this->getClassName($uuid) !== $className) {
                    continue;
                }
            } catch (Throwable $e) {
                $this->getLogger()?->log(new ObjectLoadingFailureException(sprintf('Error while loading object %s', $uuid), Exception::CODE_FAILURE_OBJECT_LOADING, $e));
                continue;
            }

            try {
                $object = $this->load($uuid);
                if ($object === null) {
                    continue;
                }

                if ($matcher($object)) {
                    $count++;
                    yield $uuid => $object;
                }
            } catch (Throwable $e) {
                $this->getLogger()?->log(new ObjectMatchingFailureException(sprintf('Error while matching object %s', $uuid), Exception::CODE_FAILURE_OBJECT_MATCH, $e));
                continue;
            }
        }
    }

    /**
     * Retrieves a collection of unique and valid UUIDs based on the provided subset.
     * If no subset is provided, retrieves the complete list of objects.
     *
     * @param array|null $subSet An optional array of UUIDs to filter. If provided, both array keys and values are considered.
     * @return iterable An iterable collection containing unique and valid UUIDs.
     */
    protected function getSelection(?array $subSet = null): iterable
    {
        return $subSet ? array_unique(array_filter([...array_values($subSet), ...array_keys($subSet)], function ($uuid) {
            return is_string($uuid) && Validator::validate($uuid);
        })) : $this->list();
    }
}