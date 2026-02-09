<?php

namespace melia\ObjectStorage\Strategy;

use melia\ObjectStorage\Context\GraphBuilderContext;
use melia\ObjectStorage\UUID\Generator\AwareInterface;

interface StrategyInterface extends AwareInterface
{
    public function inheritLifetime(?GraphBuilderContext $context = null): bool;

    public function getChecksumAlgorithm(): string;

    public function serialize(array $graph, int $depth): ?string;

    public function unserialize(string $data): array;

    public function getMaxDepth(): int;

    public function getShardDepth(): int;
}