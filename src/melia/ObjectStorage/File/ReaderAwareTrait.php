<?php

namespace melia\ObjectStorage\File;

trait ReaderAwareTrait {
    protected ?ReaderInterface $reader = null;

    public function getReader(): ?ReaderInterface
    {
        return $this->reader ?? new Reader();
    }

    public function setReader(ReaderInterface $reader): void
    {
        $this->reader = $reader;
    }
}