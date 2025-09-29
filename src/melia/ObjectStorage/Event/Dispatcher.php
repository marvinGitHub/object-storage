<?php

namespace melia\ObjectStorage\Event;

use melia\ObjectStorage\Event\Context\ContextInterface;
use Throwable;

class Dispatcher implements DispatcherInterface
{
    /** @var array<string, array<int, callable>> */
    private array $listeners = [];

    public function addListener(string $event, callable $listener): void
    {
        $this->listeners[$event] ??= [];
        // prevent duplicates
        foreach ($this->listeners[$event] as $l) {
            if ($l === $listener) {
                return;
            }
        }
        $this->listeners[$event][] = $listener;
    }

    public function removeListener(string $event, callable $listener): void
    {
        if (!isset($this->listeners[$event])) return;
        $this->listeners[$event] = array_values(array_filter(
            $this->listeners[$event],
            fn($l) => $l !== $listener
        ));
        if (!$this->listeners[$event]) unset($this->listeners[$event]);
    }

    public function dispatch(string $event, ?ContextInterface $context = null): void
    {
        if (empty($this->listeners[$event])) return;
        foreach ($this->listeners[$event] as $listener) {
            try {
                $listener($event, $context);
            } catch (Throwable $e) {
//                // Swallow to avoid breaking storage flow; optionally log
//                if (isset($context['logger']) && $context['logger'] instanceof \Psr\Log\LoggerInterface) {
//                    $context['logger']->error('Event listener error', ['event' => $event, 'exception' => $e]);
//                }
            }
        }
    }
}