<?php

namespace melia\ObjectStorage\Strategy;

use melia\ObjectStorage\Checksum\AlgorithmAwareTrait;

class Standard implements StrategyInterface
{
    use AlgorithmAwareTrait;
    private bool $inheritLifetime = false;
    public function enableLifetimeInheritance(): void
    {
        $this->inheritLifetime = true;
    }

    public function disableLifetimeInheritance(): void
    {
        $this->inheritLifetime = false;
    }
    public function inheritLifetime(): bool
    {
        return $this->inheritLifetime;
    }
}