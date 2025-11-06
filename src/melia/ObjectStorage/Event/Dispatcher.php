<?php

namespace melia\ObjectStorage\Event;

use melia\ObjectStorage\Event\Context\ContextInterface;
use melia\ObjectStorage\Exception\ContextBuilderFailureException;
use melia\ObjectStorage\Logger\LoggerAwareTrait;
use Throwable;

class Dispatcher implements DispatcherInterface
{
    use LoggerAwareTrait;

    /** @var array<string, array<int, callable>> */
    private array $listeners = [];

    /**
     * Adds a listener for a specific event. If the listener is already registered for the event, it will not be added again.
     *
     * @param string $event The name of the event to attach the listener to.
     * @param callable $listener The callable function or method to execute when the event is triggered.
     * @return void
     */
    public function addListener(string $event, callable $listener): void
    {
        $this->listeners[$event] ??= [];

        foreach ($this->listeners[$event] as $l) {
            if ($l === $listener) {
                return;
            }
        }
        $this->listeners[$event][] = $listener;
    }

    /**
     * Removes a specific listener for a given event. If the listener is not registered for the event, no action is taken.
     *
     * @param string $event The name of the event from which the listener will be removed.
     * @param callable $listener The callable function or method to be removed from the event's listener list.
     * @return void
     */
    public function removeListener(string $event, callable $listener): void
    {
        if (!isset($this->listeners[$event])) return;
        $this->listeners[$event] = array_values(array_filter(
            $this->listeners[$event],
            fn($l) => $l !== $listener
        ));
        if (!$this->listeners[$event]) unset($this->listeners[$event]);
    }

    /**
     * Dispatches an event by invoking all registered listeners for that event.
     * If a listener throws an exception, it is logged without interrupting the dispatch process.
     *
     * @param string $event The name of the event to be dispatched.
     * @param callable|null $contextBuilder
     * @return void
     */
    public function dispatch(string $event, ?callable $contextBuilder = null): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $context = null;
            try {
                if (is_callable($contextBuilder)) {
                    $context = $contextBuilder();
                    if (false === $context instanceof ContextInterface) {
                        throw new ContextBuilderFailureException('Context builder must return an instance of ' . ContextInterface::class);
                    }
                }
            } catch (Throwable $e) {
                $this->getLogger()?->log(new ContextBuilderFailureException('Context builder failed: ' . $e->getMessage(), code: $e->getCode(), previous: $e));;
            }
            try {
                $listener($context);
            } catch (Throwable $e) {
                $this->getLogger()?->log($e);
            }
        }
    }
}