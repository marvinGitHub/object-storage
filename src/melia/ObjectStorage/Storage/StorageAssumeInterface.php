<?php

namespace melia\ObjectStorage\Storage;

interface StorageAssumeInterface
{
    public function assume(StorageInterface $storage): void;
}
