<?php

namespace melia\ObjectStorage\Event;

trait AwareTrait
{
    protected ?DispatcherInterface $eventDispatcher = null;

    public function getEventDispatcher(): ?DispatcherInterface
    {
        return $this->eventDispatcher;
    }

    public function setEventDispatcher(?DispatcherInterface $eventDispatcher): void
    {
        $this->eventDispatcher = $eventDispatcher;
    }
}