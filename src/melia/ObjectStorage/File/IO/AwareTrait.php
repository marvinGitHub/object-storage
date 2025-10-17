<?php

namespace melia\ObjectStorage\File\IO;

trait AwareTrait
{
    protected ?AdapterInterface $ioAdapter = null;

    /**
     * Retrieves the current adapter instance.
     *
     * @return AdapterInterface|null The adapter instance if set, null otherwise.
     */
    public function getAdapter(): ?AdapterInterface
    {
        return $this->ioAdapter;
    }

    /**
     * Sets the I/O adapter to be used.
     *
     * @param AdapterInterface|null $ioAdapter The I/O adapter instance or null to reset.
     * @return void
     */
    public function setAdapter(?AdapterInterface $ioAdapter): void
    {
        $this->ioAdapter = $ioAdapter;
    }
}