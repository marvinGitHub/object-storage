<?php

namespace melia\ObjectStorage\State;

use melia\ObjectStorage\Event\DispatcherAwareTrait;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\SafeModeActivationFailedException;
use melia\ObjectStorage\File\IO\AdapterAwareTrait;
use melia\ObjectStorage\File\IO\RealAdapter;
use melia\ObjectStorage\File\WriterAwareTrait;
use Throwable;

class StateHandler
{
    public function __construct(private string $stateDir)
    {
        $this->setIOAdapter(new RealAdapter());
    }

    use AdapterAwareTrait;
    use WriterAwareTrait;
    use DispatcherAwareTrait;

    /**
     * Disables safe mode by removing the related safe mode file if it exists.
     *
     * @return bool Returns true if the safe mode file was successfully removed or does not exist.
     */
    public function disableSafeMode(): bool
    {
        $adapter = $this->getIOAdapter();
        if ($adapter->isFile($filename = $this->getFilePathSafeMode())) {
            $success = $adapter->unlink($filename);
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
        $adapter = $this->getIOAdapter();
        return $adapter->isFile($filename) && (bool)$adapter->fileGetContents($filename) === true;
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