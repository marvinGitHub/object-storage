<?php

namespace melia\ObjectStorage\Storage;

trait StorageAwareTrait
{
    protected ?StorageInterface $storage = null;

    public function getStorage(): ?StorageInterface
    {
        return $this->storage;
    }

    public function setStorage(?StorageInterface $storage = null): void
    {
        $this->storage = $storage;
    }
}