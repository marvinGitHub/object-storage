<?php

namespace melia\ObjectStorage\Event\Context;

class StubCreationContext extends Context
{

    public function __construct(string $uuid, protected string $className)
    {
        parent::__construct($uuid);
    }

    public function getClassName(): string
    {
        return $this->className;
    }
}