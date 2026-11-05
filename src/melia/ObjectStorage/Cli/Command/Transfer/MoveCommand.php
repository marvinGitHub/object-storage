<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command\Transfer;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Moves objects into another storage: copies them and deletes them in the source.
 */
#[AsCommand(name: 'move')]
final class MoveCommand extends TransferCommand
{
    protected function isMove(): bool
    {
        return true;
    }
}
