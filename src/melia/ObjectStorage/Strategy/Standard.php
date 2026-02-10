<?php

namespace melia\ObjectStorage\Strategy;

use melia\ObjectStorage\Checksum\AlgorithmAwareTrait;
use melia\ObjectStorage\Context\GraphBuilderContext;
use melia\ObjectStorage\Exception\InvalidChildWritePolicyException;
use melia\ObjectStorage\Exception\InvalidMaxDepthException;
use melia\ObjectStorage\UUID\Generator\AwareTrait as GeneratorAwareTrait;
use melia\ObjectStorage\UUID\Validator;

class Standard implements StrategyInterface
{
    use AlgorithmAwareTrait;
    use GeneratorAwareTrait;

    const SHARD_DEPTH_DEFAULT = 2;

    private bool $inheritLifetime = false;
    private int $maxDepth = 100;
    private int $shardDepth = Standard::SHARD_DEPTH_DEFAULT;
    private int $childWritePolicy = self::POLICY_CHILD_WRITE_IF_NOT_EXIST;

    public function enableLifetimeInheritance(): void
    {
        $this->inheritLifetime = true;
    }

    public function disableLifetimeInheritance(): void
    {
        $this->inheritLifetime = false;
    }

    public function inheritLifetime(?GraphBuilderContext $context = null): bool
    {
        return $this->inheritLifetime;
    }

    public function serialize(array $graph, int $depth): ?string
    {
        return json_encode(value: $graph, depth: $depth) ?: null;
    }

    public function unserialize(string $data): array
    {
        return json_decode(json: $data, associative: true) ?: [];
    }

    /**
     * Retrieves the maximum allowable depth.
     *
     * @return int The current maximum depth value.
     */
    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    /**
     * Sets the maximum allowable depth.
     *
     * @param int $maxDepth The maximum depth value. Must be greater than 0.
     * @return void
     * @throws InvalidMaxDepthException If the provided max depth is less than or equal to 0.
     */
    public function setMaxDepth(int $maxDepth): void
    {
        if ($maxDepth <= 0) {
            throw new InvalidMaxDepthException('Max depth must be greater than 0.');
        }
        $this->maxDepth = $maxDepth;
    }

    /**
     * Retrieves the current shard depth.
     *
     * @return int The depth of the shard.
     */
    public function getShardDepth(): int
    {
        return $this->shardDepth;
    }

    /**
     * Sets the shard depth for the current instance.
     *
     * @param int $shardDepth The depth value to set. Must be between 0 and 32, inclusive.
     * @return void
     * @throws InvalidMaxDepthException If the provided shard depth is not within the allowed range.
     */
    public function setShardDepth(int $shardDepth): void
    {
        $maxShardDepth = Validator::UUID_LENGTH - 1;

        if ($shardDepth <= 0 || $shardDepth > $maxShardDepth) {
            throw new InvalidMaxDepthException(sprintf('Shard depth must be between 0 and %u, inclusive.', $maxShardDepth));
        }
        $this->shardDepth = $shardDepth;
    }

    /**
     * Retrieves the current child write policy.
     *
     * @return int The current child write policy. Possible values include:
     *             - POLICY_CHILD_WRITE_ALWAYS
     *             - POLICY_CHILD_WRITE_IF_NOT_EXIST
     *             - POLICY_CHILD_WRITE_NEVER
     */
    public function getChildWritePolicy(): int
    {
        return $this->childWritePolicy;
    }

    /**
     * Sets the child write policy for the current object.
     *
     * @param int $childWritePolicy The child write policy to set. Valid values are:
     *                              - POLICY_CHILD_WRITE_ALWAYS
     *                              - POLICY_CHILD_WRITE_IF_NOT_EXIST
     *                              - POLICY_CHILD_WRITE_NEVER
     *                              If an invalid value is provided, an InvalidChildWritePolicyException will be thrown.
     *
     * @return void
     * @throws InvalidChildWritePolicyException
     */
    public function setChildWritePolicy(int $childWritePolicy): void
    {
        if (!in_array($childWritePolicy, [self::POLICY_CHILD_WRITE_ALWAYS, self::POLICY_CHILD_WRITE_IF_NOT_EXIST, self::POLICY_CHILD_WRITE_NEVER])) {
            throw new InvalidChildWritePolicyException('Invalid child write policy.');
        }
        $this->childWritePolicy = $childWritePolicy;
    }
}