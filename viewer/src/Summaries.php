<?php

namespace melia\ObjectStorage\Viewer;

use melia\ObjectStorage\Configuration\Configuration;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Search\DocumentBuilder;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\Index\IndexInterface;
use melia\ObjectStorage\Strategy\Policy\ChildDeletion;
use melia\ObjectStorage\Strategy\Policy\ChildPersistence;
use Throwable;

/**
 * Summaries of search indexes, configurations and stored objects, as the pages show them.
 */
final class Summaries
{
    /**
     * A search index as the list of indexes shows it.
     */
    public static function describeIndex(IndexInterface $index): array
    {
        $definition = $index->getDefinition();
        $state = $index->getState();

        return [
            'name' => $index->getName(),
            'engine' => $index->getEngineName(),
            'className' => $definition->className,
            'status' => $index->getStatus(),
            'documents' => $index->countDocuments(),
            'size' => $index->getSize(),
            'pending' => $index->countPendingChanges(),
            'fields' => array_map(static fn(Field $field): array => $field->toArray(), $definition->fields),
            'languages' => $definition->languages,
            'lastBuildAt' => $state->lastBuildAt,
            'lastSyncAt' => $state->lastSyncAt,
            'updates' => $state->updates,
            'lastUpdate' => $state->getLastUpdate(),
            // states stored before the updates were recorded only know the last build and sync
            'lastUpdatedAt' => $state->getLastUpdate()['at'] ?? (array_filter([$state->lastBuildAt, $state->lastSyncAt])
                ? max(array_map('strtotime', array_filter([$state->lastBuildAt, $state->lastSyncAt])))
                : null),
            'errors' => $state->errors,
            'progress' => $index->getProgress($state),
        ];
    }

    /**
     * A configuration for lists: name, description and short summaries.
     */
    public static function describeConfiguration(Configuration $configuration): array
    {
        $strategy = array_filter([
            null === $configuration->shardDepth ? null : 'shard depth ' . $configuration->shardDepth,
            null === $configuration->maxDepth ? null : 'max depth ' . $configuration->maxDepth,
            $configuration->lifetimeInheritance ? 'lifetime inheritance' : null,
            $configuration->checksumValidation ? null : 'no checksum validation',
            $configuration->optimisticLocking ? 'optimistic locking' : null,
            null === $configuration->childPersistence ? null : 'children stored ' . ([ChildPersistence::ALWAYS => 'ALWAYS', ChildPersistence::NEVER => 'NEVER', ChildPersistence::IF_NOT_EXIST => 'IF_NOT_EXIST'][$configuration->childPersistence] ?? '?'),
            null === $configuration->childDeletion ? null : 'children deleted ' . ([ChildDeletion::ALWAYS => 'ALWAYS', ChildDeletion::NEVER => 'NEVER'][$configuration->childDeletion] ?? '?'),
            $configuration->fsync ? null : 'no fsync',
        ]);

        return [
            'name' => $configuration->name,
            'description' => $configuration->description,
            'lock' => $configuration->lock->describe(),
            'lockType' => $configuration->lock->type,
            'cache' => $configuration->cache->describe(),
            'cacheType' => $configuration->cache->type,
            'strategy' => [] === $strategy ? 'defaults' : implode(', ', $strategy),
        ];
    }

    /**
     * Field suggestions for a search index from stored objects of the class: path => type.
     */
    public static function suggestFields(ObjectStorage $storage, string $className): array
    {
        foreach ($storage->list($className) as $uuid) {
            $metadata = $storage->loadMetadata($uuid);
            $data = json_decode((string)@file_get_contents($storage->getFilePathData($uuid)), true);
            if (null === $metadata || !is_array($data)) {
                continue;
            }

            // also the values of referenced objects, e.g. "category.title"
            $follow = static function (string $uuid) use ($storage): ?array {
                try {
                    $object = $storage->readRaw($uuid);
                    $data = json_decode($object->data, true);
                    return is_array($data) ? [$data, $object->metadata->getReservedReferenceName(), $object->metadata->getClassName()] : null;
                } catch (Throwable) {
                    return null;
                }
            };

            // a few objects, as a value may be missing in one of them, e.g. a product without a category
            $samples = [[$data, $metadata->getReservedReferenceName()]];
            foreach ($storage->list($className) as $other) {
                if (count($samples) >= 20) {
                    break;
                }
                if ($other !== $uuid && is_array($sample = $follow($other))) {
                    $samples[] = $sample;
                }
            }

            return array_map(
                static fn(Field $field): array => $field->toArray(),
                DocumentBuilder::suggestFieldsFrom($samples, $follow)
            );
        }

        return [];
    }
}
