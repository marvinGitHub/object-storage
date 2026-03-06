<?php

namespace melia\ObjectStorage\Strategy;

use melia\ObjectStorage\Context\GraphBuilderContext;
use melia\ObjectStorage\UUID\Generator\AwareInterface;

interface StrategyInterface extends AwareInterface
{
    public const POLICY_CHILD_WRITE_ALWAYS = 1;
    public const POLICY_CHILD_WRITE_NEVER = 2;
    public const POLICY_CHILD_WRITE_IF_NOT_EXIST = 3;
    public const POLICY_CHILD_WRITE_CALLBACK = 4;

    public const DEFAULT_MAX_DEPTH = 512;
    public const DEFAULT_SHARD_DEPTH = 2;
    public const DEFAULT_POLICY_CHILD_WRITE = self::POLICY_CHILD_WRITE_IF_NOT_EXIST;

    public function inheritLifetime(?GraphBuilderContext $context = null): bool;

    public function getChecksumAlgorithm(): string;

    public function serialize(array $graph, int $depth): ?string;

    public function unserialize(string $data): array;

    public function getMaxDepth(): int;

    public function getShardDepth(): int;

    public function getChildWritePolicy(): int;

    /**
     * Decide whether a referenced child object should be written when encountered during graph building.
     *
     * Only used when getChildWritePolicy() === POLICY_CHILD_WRITE_CALLBACK.
     *
     * @param GraphBuilderContext $context     Current graph builder context (parent + metadata + level)
     * @param object             $child       The referenced child object (already resolved from LazyLoadReference if applicable)
     * @param string             $childUuid   UUID assigned to the child (existing or newly generated)
     * @param bool               $childExists Whether the child already exists in storage
     * @param array              $path        Path within the object graph where the child reference was found
     */
    public function shouldWriteChild(
        GraphBuilderContext $context,
        object $child,
        string $childUuid,
        bool $childExists,
        array $path
    ): bool;
}