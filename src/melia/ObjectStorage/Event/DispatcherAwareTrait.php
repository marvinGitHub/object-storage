<?php

namespace melia\ObjectStorage\Event;

trait DispatcherAwareTrait
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

	/**
	 * Checks whether any listener is registered for one of the given events. Dispatchers that
	 * don't expose their listeners are assumed to have listeners.
	 *
	 * @param string ...$events The names of the events.
	 * @return bool True if a listener might be registered for one of the events, false otherwise.
	 */
	protected function hasListeners(string ...$events): bool
	{
		$eventDispatcher = $this->getEventDispatcher();

		if (null === $eventDispatcher) {
			return false;
		}

		if (false === $eventDispatcher instanceof Dispatcher) {
			return true;
		}

		foreach ($events as $event) {
			if ($eventDispatcher->hasListeners($event)) {
				return true;
			}
		}

		return false;
	}
}