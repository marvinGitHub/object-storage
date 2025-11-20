<?php

namespace melia\ObjectStorage\Context;

use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\Metadata\MetadataAwareTrait;

class GraphBuilderContext
{
    private object $target;
    private int $level;
    use MetadataAwareTrait;

    public function __construct(object $target, Metadata $metadata, int $level = 1)
    {
        $this->target = $target;
        $this->metadata = $metadata;
        $this->level = $level;
    }

    public function getTarget(): object
    {
        return $this->target;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function getMetadata(): Metadata
    {
        return $this->metadata;
    }
}