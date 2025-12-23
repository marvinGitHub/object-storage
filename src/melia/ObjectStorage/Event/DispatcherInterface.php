<?php

namespace melia\ObjectStorage\Event;
interface DispatcherInterface
{
    public function addListener(string $event, callable $listener): void;

    public function removeListener(string $event, callable $listener): void;

    public function dispatch(string $event, ?callable $contextBuilder = null): void;
}