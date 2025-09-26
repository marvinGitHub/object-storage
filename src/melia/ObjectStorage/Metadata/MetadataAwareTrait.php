<?php

namespace melia\ObjectStorage\Metadata;

trait MetadataAwareTrait
{
    protected ?Metadata $metadata = null;

    public function getMetadata(): ?Metadata
    {
        return $this->metadata;
    }

    public function setMetadata(Metadata $metadata): void
    {
        $this->metadata = $metadata;
    }
}