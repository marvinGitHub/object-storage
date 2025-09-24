<?php

namespace melia\ObjectStorage\Logger;

trait LoggerAwareTrait
{

    protected ?LoggerInterface $logger = null;

    /**
     * Retrieves the logger instance.
     *
     * @return LoggerInterface|null The logger instance if available, or null if no logger is set.
     */
    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Sets the logger instance for the class.
     *
     * @param LoggerInterface|null $logger The logger instance to be used for logging messages.
     * @return void
     */
    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
