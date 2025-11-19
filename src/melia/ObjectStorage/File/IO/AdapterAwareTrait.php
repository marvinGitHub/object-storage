<?php

namespace melia\ObjectStorage\File\IO;

trait AdapterAwareTrait
{
    protected ?AdapterInterface $ioAdapter = null;

    /**
     * Retrieves the current adapter instance.
     *
     * @return AdapterInterface|null The adapter instance if set, null otherwise.
     */
    public function getIOAdapter(): ?AdapterInterface
    {
        return $this->ioAdapter ?? new RealAdapter();
    }

    /**
     * Sets the I/O adapter to be used.
     *
     * @param AdapterInterface|null $ioAdapter The I/O adapter instance or null to reset.
     * @return void
     */
    public function setIOAdapter(?AdapterInterface $ioAdapter): void
    {
        $this->ioAdapter = $ioAdapter;
    }
}