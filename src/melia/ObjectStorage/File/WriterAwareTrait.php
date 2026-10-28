<?php

namespace melia\ObjectStorage\File;

trait WriterAwareTrait
{
    protected ?WriterInterface $writer = null;

    public function getWriter(): WriterInterface
    {
        // keep the default writer, so its configuration (e.g. setSyncEnabled()) applies to later writes
        return $this->writer ??= new Writer();
    }

    public function setWriter(WriterInterface $writer): void
    {
        $this->writer = $writer;
    }
}