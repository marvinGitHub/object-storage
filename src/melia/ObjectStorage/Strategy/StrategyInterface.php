<?php

namespace melia\ObjectStorage\Strategy;

interface StrategyInterface
{
    public function inheritLifetime(): bool;
}