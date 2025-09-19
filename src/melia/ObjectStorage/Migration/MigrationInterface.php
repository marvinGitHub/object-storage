<?php

namespace melia\ObjectStorage\Migration;

interface MigrationInterface
{
    public function apply(object $object): bool;
}