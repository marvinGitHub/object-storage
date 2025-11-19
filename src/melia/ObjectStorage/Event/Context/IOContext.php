<?php

namespace melia\ObjectStorage\Event\Context;

class IOContext implements ContextInterface
{
    public function __construct(protected string $path)
    {

    }

    public function getPath(): string
    {
        return $this->path;
    }
}