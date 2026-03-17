<?php

namespace melia\ObjectStorage\Strategy;

use melia\ObjectStorage\Context\GraphBuilderContext;
use melia\ObjectStorage\Strategy\Policy\ChildWrite;
use melia\ObjectStorage\Strategy\Policy\StaticProperty;
use melia\ObjectStorage\UUID\Generator\AwareInterface;

interface StrategyInterface extends AwareInterface
{
    public const int DEFAULT_MAX_DEPTH = 512;
    public const int DEFAULT_SHARD_DEPTH = 2;
    public const int DEFAULT_POLICY_CHILD_WRITE = ChildWrite::IF_NOT_EXIST;
    public const int DEFAULT_POLICY_STATIC_PROPERTY = StaticProperty::NEVER;

    public function inheritLifetime(?GraphBuilderContext $context = null): bool;

    public function getChecksumAlgorithm(): string;

    public function serialize(array $graph, int $depth): ?string;

    public function unserialize(string $data): array;

    public function getMaxDepth(): int;

    public function getShardDepth(): int;

    public function getPolicyChildWrite(): int;

    public function checksumValidationEnabled(): bool;

    public function getPolicyStaticProperty() : int;

    /**
     * Decide whether a referenced child object should be written when encountered during graph building.
     *
     * Only used when getChildWritePolicy() === ChildWritePolicy_CALLBACK.
     *
     * @param GraphBuilderContext $context Current graph builder context (parent + metadata + level)
     * @param object $child The referenced child object (already resolved from LazyLoadReference if applicable)
     * @param string $childUuid UUID assigned to the child (existing or newly generated)
     * @param bool $childExists Whether the child already exists in storage
     * @param array $path Path within the object graph where the child reference was found
     */
    public function shouldWriteChild(
        GraphBuilderContext $context,
        object              $child,
        string              $childUuid,
        bool                $childExists,
        array               $path
    ): bool;

    /**
     * Determines whether a static property of a class should be persisted.
     *
     * This method evaluates if the specified static property and its value
     * should be included in the persistence process.
     *
     * @param string $className The fully qualified name of the class containing the static property.
     * @param string $propertyName The name of the static property being evaluated.
     * @param mixed $value The current value of the static property.
     *
     * @return bool True if the static property should be persisted, false otherwise.
     */
    public function shouldPersistStaticProperty(
        string $className,
        string $propertyName,
        mixed $value
    ): bool;
}