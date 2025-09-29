<?php

namespace melia\ObjectStorage\Event\Context;

use melia\ObjectStorage\UUID\AwareTrait;
use melia\ObjectStorage\UUID\Exception\InvalidUUIDException;

class Context implements ContextInterface
{
    use AwareTrait;

    /**
     * @throws InvalidUUIDException
     */
    public function __construct(?string $uuid = null)
    {
        if ($uuid) {
            $this->setUUID($uuid);
        }
    }
}