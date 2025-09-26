<?php

namespace melia\ObjectStorage\Context;

use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\Metadata\MetadataAwareTrait;

class GraphBuilderContext
{
    private object $target;
    use MetadataAwareTrait;

    public function __construct(object $target, Metadata $metadata)
    {
        $this->target = $target;
        $this->metadata = $metadata;
    }

    public function getTarget(): object
    {
        return $this->target;
    }
}