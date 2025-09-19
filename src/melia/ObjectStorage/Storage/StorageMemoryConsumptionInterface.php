<?php

namespace melia\ObjectStorage\Storage;

interface StorageMemoryConsumptionInterface
{
    public function getMemoryConsumption(string $uuid): int;
}