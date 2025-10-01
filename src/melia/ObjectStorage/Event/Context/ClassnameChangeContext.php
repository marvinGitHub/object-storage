<?php

namespace melia\ObjectStorage\Event\Context;

class ClassnameChangeContext extends Context
{
    public function __construct(
        string        $uuid,
        public string $previousClassName,
        public string $newClassName,
    )
    {
        parent::__construct($uuid);

    }

    public function getPreviousClassName(): string
    {
        return $this->previousClassName;
    }

    public function getNewClassName(): string
    {
        return $this->newClassName;
    }
}