<?php

namespace melia\ObjectStorage\Strategy;

use melia\ObjectStorage\Checksum\AlgorithmAwareTrait;
use melia\ObjectStorage\Context\GraphBuilderContext;
use melia\ObjectStorage\Exception\InvalidMaxDepthException;
use melia\ObjectStorage\UUID\Generator\AwareTrait as GeneratorAwareTrait;
use melia\ObjectStorage\UUID\Validator;

class Standard implements StrategyInterface
{
    use AlgorithmAwareTrait;
    use GeneratorAwareTrait;

    private bool $inheritLifetime = false;
    private int $maxDepth = 100;
    private int $shardDepth = 2;

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
     * @param int $shardDepth The depth value to set. Must be between 1 and 32, inclusive.
     * @return void
     * @throws InvalidMaxDepthException If the provided shard depth is not within the allowed range.
     */
    public function setShardDepth(int $shardDepth): void
    {
        $maxShardDepth = Validator::UUID_LENGTH - 1;

        if ($shardDepth <= 0 || $shardDepth > $maxShardDepth) {
            throw new InvalidMaxDepthException(sprintf('Shard depth must be between 1 and %u, inclusive.', $maxShardDepth));
        }
        $this->shardDepth = $shardDepth;
    }
}