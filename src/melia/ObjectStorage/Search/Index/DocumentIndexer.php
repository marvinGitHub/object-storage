<?php

namespace melia\ObjectStorage\Search\Index;

use JsonException;
use melia\ObjectStorage\Exception\ObjectLoadingFailureException;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Search\DocumentBuilder;
use melia\ObjectStorage\Search\Engine\EngineIndexInterface;
use melia\ObjectStorage\Search\Exception\SearchException;
use Throwable;

/**
 * Indexes objects in a generation of a search index: builds their documents from their stored data and writes them
 * to the engine, or removes them; finds the documents built from referenced objects.
 *
 * Keeps the classes of the referenced objects read (paths into referenced objects), until they are taken.
 *
 * @internal part of Index
 */
final class DocumentIndexer
{
    /** the documents asked for at once when looking for those built from referenced objects */
    public const int REFERENCING_LIMIT = 500;

    /** @var array<string, true> classes of referenced objects read since they were taken last */
    private array $joinedClasses = [];

    /**
     * @param ObjectStorage $storage The storage of the indexed objects.
     * @param Generations $generations The generations of the index.
     */
    public function __construct(
        private readonly ObjectStorage $storage,
        private readonly Generations   $generations,
    )
    {
    }

    /**
     * Indexes the current state of the given objects in a generation: stored objects of the indexed class are
     * added or updated, all others (deleted, expired, of another class) are removed. An object that fails is
     * removed, so no outdated version of it stays searchable.
     *
     * @param string[] $uuids
     * @return array{failed: int, errors: string[]} The objects that failed, with their errors.
     * @throws SearchException
     */
    public function apply(int $generation, array $uuids): array
    {
        $definition = $this->generations->definition($generation);
        // paths lead into referenced objects, read like the objects themselves
        $builder = new DocumentBuilder($definition, function (string $uuid): ?array {
            $stored = $this->readObject($uuid);
            return null === $stored ? null : [$stored[1], $stored[0]->getReservedReferenceName(), $stored[0]->getClassName()];
        });
        $handle = $this->generations->open($generation);

        $documents = [];
        $remove = [];
        $errors = [];

        foreach (array_unique($uuids) as $uuid) {
            try {
                $stored = $this->readObject($uuid);
                if (null === $stored || $stored[0]->getClassName() !== $definition->className) {
                    $remove[] = $uuid;
                    continue;
                }
                $documents[$uuid] = $builder->build($uuid, $stored[1], $stored[0]);
            } catch (Throwable $e) {
                $remove[] = $uuid;
                $errors[] = sprintf('%s: %s', $uuid, $e->getMessage());
            }
        }

        if ([] !== $documents) {
            // documents the engine rejects (e.g. a type that doesn't match the other documents) are removed
            foreach ($handle->upsert($documents) as $uuid => $message) {
                $remove[] = (string)$uuid;
                $errors[] = sprintf('%s: %s', $uuid, $message);
            }
        }

        if ([] !== $remove) {
            $handle->delete(array_values(array_unique($remove)));
        }

        foreach ($builder->getJoinedClasses() as $className) {
            $this->joinedClasses[$className] = true;
        }

        return ['failed' => count($errors), 'errors' => $errors];
    }

    /**
     * The classes of the referenced objects read since they were taken last.
     *
     * @return string[]
     */
    public function takeJoinedClasses(): array
    {
        $classes = array_map('strval', array_keys($this->joinedClasses));
        $this->joinedClasses = [];
        return $classes;
    }

    /**
     * The documents of a generation built from referenced objects. The engine returns a limited number at once: if
     * the limit is reached, the UUIDs are asked for by longer prefixes, so any number of documents is found.
     *
     * @param string[] $uuids UUIDs of referenced objects.
     * @return string[]
     * @throws SearchException
     */
    public function referencing(int $generation, array $uuids): array
    {
        return $this->findReferencing($this->generations->open($generation), $uuids);
    }

    /**
     * @param string[] $uuids
     * @return string[]
     */
    private function findReferencing(EngineIndexInterface $handle, array $uuids, string $prefix = ''): array
    {
        $found = $handle->referencing($uuids, self::REFERENCING_LIMIT, $prefix);
        if (count($found) < self::REFERENCING_LIMIT || strlen($prefix) >= 36) {
            return $found;
        }

        // the next character of a UUID: the hyphens are at fixed positions
        $next = in_array(strlen($prefix), [8, 13, 18, 23], true) ? ['-'] : str_split('0123456789abcdefABCDEF');
        $result = [];
        foreach ($next as $character) {
            array_push($result, ...$this->findReferencing($handle, $uuids, $prefix . $character));
        }

        return $result;
    }

    /**
     * Reads the metadata and data of an object as stored (ObjectStorage::findRaw()): from the files, not the caches
     * of the storage, which may be outdated in long-running processes, under its lock, with the checksum verified
     * and interrupted writes completed, as when loading. The object isn't loaded, so its class needn't be available.
     *
     * @return array{0: Metadata, 1: array}|null Null if nothing (or an expired object) is stored.
     * @throws ObjectLoadingFailureException If the object can't be read, e.g. its data doesn't match its checksum.
     * @throws JsonException
     */
    private function readObject(string $uuid): ?array
    {
        $object = $this->storage->findRaw($uuid);
        if (null === $object) {
            return null;
        }

        // unlike RawObject::decodedData(), invalid JSON is an error of the document
        $data = json_decode($object->data, true, 512, JSON_THROW_ON_ERROR);

        return [$object->metadata, is_array($data) ? $data : []];
    }
}
