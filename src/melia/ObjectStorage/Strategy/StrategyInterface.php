<?php

namespace melia\ObjectStorage\Strategy;

interface StrategyInterface
{
    public function inheritLifetime(): bool;
    public function getChecksumAlgorithm(): string;
}