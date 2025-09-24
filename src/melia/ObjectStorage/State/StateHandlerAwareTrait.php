<?php

namespace melia\ObjectStorage\State;

trait StateHandlerAwareTrait
{
    protected ?StateHandler $stateHandler = null;

    public function getStateHandler(): ?StateHandler
    {
        return $this->stateHandler;
    }

    public function setStateHandler(StateHandler $stateHandler): void
    {
        $this->stateHandler = $stateHandler;
    }
}