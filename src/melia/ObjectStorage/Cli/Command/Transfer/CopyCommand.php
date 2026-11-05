<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command\Transfer;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Copies objects into another storage.
 */
#[AsCommand(name: 'copy')]
final class CopyCommand extends TransferCommand
{
    protected function isMove(): bool
    {
        return false;
    }
}
