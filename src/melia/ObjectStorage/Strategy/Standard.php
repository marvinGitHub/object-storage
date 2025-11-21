<?php

namespace melia\ObjectStorage\Strategy;

class Standard implements StrategyInterface
{
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