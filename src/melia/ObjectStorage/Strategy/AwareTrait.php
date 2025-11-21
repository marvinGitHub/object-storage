<?php

namespace melia\ObjectStorage\Strategy;

trait AwareTrait
{
    protected ?StrategyInterface $strategy = null;

    public function getStrategy(): ?StrategyInterface
    {
        return $this->strategy;
    }

    public function setStrategy(StrategyInterface $strategy): void
    {
        $this->strategy = $strategy;
    }
}