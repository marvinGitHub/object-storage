<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli;

use melia\ObjectStorage\Cli\Command\CheckCommand;
use melia\ObjectStorage\Cli\Command\DeleteCommand;
use melia\ObjectStorage\Cli\Command\GetCommand;
use melia\ObjectStorage\Cli\Command\LifetimeCommand;
use melia\ObjectStorage\Cli\Command\ListCommand;
use melia\ObjectStorage\Cli\Command\PutCommand;
use melia\ObjectStorage\Cli\Command\SafeModeCommand;
use melia\ObjectStorage\Cli\Command\StatsCommand;
use Symfony\Component\Console\Application as SymfonyApplication;

final class Application extends SymfonyApplication
{
    public function __construct()
    {
        parent::__construct('object-storage', '0.1.0');

        // Register commands here
        $this->addCommands([
            new ListCommand(),
            new GetCommand(),
            new PutCommand(),
            new DeleteCommand(),
            new CheckCommand(),
            new StatsCommand(),
            new SafeModeCommand(),
            new LifetimeCommand()
        ]);

        // Global styles can be further customized in individual commands via Styles
    }
}