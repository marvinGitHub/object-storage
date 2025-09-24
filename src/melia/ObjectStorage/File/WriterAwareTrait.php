<?php

namespace melia\ObjectStorage\File;

trait WriterAwareTrait
{
    protected ?WriterInterface $writer = null;

    public function getWriter(): WriterInterface
    {
        return $this->writer ?? new Writer();
    }

    public function setWriter(WriterInterface $writer): void
    {
        $this->writer = $writer;
    }
}