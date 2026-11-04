<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command\Transfer;

/**
 * Moves objects into another storage: copies them and deletes them in the source.
 */
final class MoveCommand extends TransferCommand
{
    protected static $defaultName = 'move';

    protected function isMove(): bool
    {
        return true;
    }
}
