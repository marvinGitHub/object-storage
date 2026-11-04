<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command\Transfer;

/**
 * Copies objects into another storage.
 */
final class CopyCommand extends TransferCommand
{
    protected static $defaultName = 'copy';

    protected function isMove(): bool
    {
        return false;
    }
}
