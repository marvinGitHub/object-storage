<?php

namespace melia\ObjectStorage\Strategy;

use melia\ObjectStorage\Checksum\AlgorithmAwareTrait;
use melia\ObjectStorage\Context\GraphBuilderContext;
use melia\ObjectStorage\UUID\Generator\AwareTrait as GeneratorAwareTrait;

class Standard implements StrategyInterface
{
    use AlgorithmAwareTrait;
    use GeneratorAwareTrait;

    private bool $inheritLifetime = false;

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
}