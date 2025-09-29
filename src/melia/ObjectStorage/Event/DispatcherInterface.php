<?php

namespace melia\ObjectStorage\Event;

use melia\ObjectStorage\Event\Context\ContextInterface;

interface DispatcherInterface
{
    public function addListener(string $event, callable $listener): void;

    public function removeListener(string $event, callable $listener): void;

    public function dispatch(string $event, ?ContextInterface $context = null): void;
}