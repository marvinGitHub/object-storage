<?php

namespace melia\ObjectStorage\Event\Context;

class LifetimeContext extends Context
{
    public function __construct(string $uuid, protected ?float $expiresAt)
    {
        parent::__construct($uuid);
    }

    public function getExpiresAt(): ?float
    {
        return $this->expiresAt;
    }
}