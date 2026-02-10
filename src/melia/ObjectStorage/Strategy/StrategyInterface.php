<?php

namespace melia\ObjectStorage\Strategy;

use melia\ObjectStorage\Context\GraphBuilderContext;
use melia\ObjectStorage\UUID\Generator\AwareInterface;

interface StrategyInterface extends AwareInterface
{
    const POLICY_CHILD_WRITE_ALWAYS = 1;
    const POLICY_CHILD_WRITE_NEVER = 2;
    const POLICY_CHILD_WRITE_IF_NOT_EXIST = 3;

    public function inheritLifetime(?GraphBuilderContext $context = null): bool;

    public function getChecksumAlgorithm(): string;

    public function serialize(array $graph, int $depth): ?string;

    public function unserialize(string $data): array;

    public function getMaxDepth(): int;

    public function getShardDepth(): int;

    public function getChildWritePolicy(): int;
}