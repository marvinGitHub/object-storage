<?php

namespace melia\ObjectStorage\Event\Context;

class ObjectPersistenceContext extends Context
{
    public function __construct(?string $uuid, protected object $object, protected ?object $previousObject = null)
    {
        parent::__construct($uuid);
    }

    public function getObject(): object
    {
        return $this->object;
    }

    public function getPreviousObject(): ?object
    {
        return $this->previousObject;
    }
}