<?php

namespace melia\ObjectStorage\Event\Context;

class LifetimeContext extends Context
{
    public function __construct(string $uuid, protected int $expiresAt)
    {
        parent::__construct($uuid);
    }

    public function getExpiresAt(): int
    {
        return $this->expiresAt;
    }
}