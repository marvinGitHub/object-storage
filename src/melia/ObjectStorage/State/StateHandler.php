<?php

namespace melia\ObjectStorage\State;

use melia\ObjectStorage\Event\AwareTrait;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\SafeModeActivationFailedException;
use melia\ObjectStorage\File\WriterAwareTrait;
use Throwable;

class StateHandler
{
    public function __construct(private string $stateDir)
    {
    }

    use WriterAwareTrait;
    use AwareTrait;

    /**
     * Disables safe mode by removing the related safe mode file if it exists.
     *
     * @return bool Returns true if the safe mode file was successfully removed or does not exist.
     */
    public function disableSafeMode(): bool
    {
        if (file_exists($filename = $this->getFilePathSafeMode())) {
            $success = unlink($filename);
            $this->eventDispatcher->dispatch(Events::SAFE_MODE_DISABLED);
            return $success;
        }
        return true;
    }

    /**
     * Retrieves the file path for safe mode storage based on the storage directory.
     *
     * @return string Returns the full file path for safe mode storage.
     */
    private function getFilePathSafeMode(): string
    {
        return $this->stateDir . DIRECTORY_SEPARATOR . 'safeMode';
    }

    /**
     * Determines whether the safe mode is enabled by checking the existence and content of a specific file.
     *
     * @return bool Returns true if safe mode is enabled, otherwise false.
     */
    public function safeModeEnabled(): bool
    {
        $filename = $this->getFilePathSafeMode();
        return file_exists($filename) && (bool)file_get_contents($filename) === true;
    }

    /**
     * Enables safe mode by performing an atomic writing to a designated file.
     *
     * @return bool Returns true if safe mode was successfully enabled, or false if an error occurred during the process.
     * @throws SafeModeActivationFailedException
     */
    public function enableSafeMode(): bool
    {
        try {
            $this->getWriter()->atomicWrite($this->getFilePathSafeMode(), '1');
            $this->getEventDispatcher()?->dispatch(Events::SAFE_MODE_ENABLED);
            return true;
        } catch (Throwable $e) {
            throw new SafeModeActivationFailedException('Unable to enable safe mode', 0, $e);
        }
    }
}