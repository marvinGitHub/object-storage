<?php

namespace melia\ObjectStorage\Event\Context;

class ObjectPersistenceContext extends Context
{
    public function __construct(string $uuid, protected object $object)
    {
        parent::__construct($uuid);
    }

    public function getObject(): object
    {
        return $this->object;
    }
}