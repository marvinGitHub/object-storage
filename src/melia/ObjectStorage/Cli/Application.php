<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli;

use melia\ObjectStorage\Cli\Command\CheckCommand;
use melia\ObjectStorage\Cli\Command\Configuration\ConfigurationAssignCommand;
use melia\ObjectStorage\Cli\Command\Configuration\ConfigurationCheckCommand;
use melia\ObjectStorage\Cli\Command\Configuration\ConfigurationDeleteCommand;
use melia\ObjectStorage\Cli\Command\Configuration\ConfigurationExportCommand;
use melia\ObjectStorage\Cli\Command\Configuration\ConfigurationImportCommand;
use melia\ObjectStorage\Cli\Command\Configuration\ConfigurationListCommand;
use melia\ObjectStorage\Cli\Command\ConfigureShardDepthCommand;
use melia\ObjectStorage\Cli\Command\Transfer\CopyCommand;
use melia\ObjectStorage\Cli\Command\Transfer\MoveCommand;
use melia\ObjectStorage\Cli\Command\Unique\UniqueCreateCommand;
use melia\ObjectStorage\Cli\Command\Unique\UniqueDropCommand;
use melia\ObjectStorage\Cli\Command\Unique\UniqueFindCommand;
use melia\ObjectStorage\Cli\Command\Unique\UniqueListCommand;
use melia\ObjectStorage\Cli\Command\Unique\UniqueRebuildCommand;
use melia\ObjectStorage\Cli\Command\DeleteCommand;
use melia\ObjectStorage\Cli\Command\GetCommand;
use melia\ObjectStorage\Cli\Command\LifetimeCommand;
use melia\ObjectStorage\Cli\Command\TouchCommand;
use melia\ObjectStorage\Cli\Command\ListCommand;
use melia\ObjectStorage\Cli\Command\Maintenance\PurgeExpiredCommand;
use melia\ObjectStorage\Cli\Command\Maintenance\RebuildShardsCommand;
use melia\ObjectStorage\Cli\Command\Maintenance\RebuildStubsCommand;
use melia\ObjectStorage\Cli\Command\PutCommand;
use melia\ObjectStorage\Cli\Command\QueryCommand;
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
            new LifetimeCommand(),
            new TouchCommand(),
            new RebuildShardsCommand(),
            new RebuildStubsCommand(),
            new ConfigureShardDepthCommand(),
            new PurgeExpiredCommand(),
            new ConfigurationListCommand(),
            new ConfigurationAssignCommand(),
            new ConfigurationCheckCommand(),
            new ConfigurationExportCommand(),
            new ConfigurationImportCommand(),
            new ConfigurationDeleteCommand(),
            new CopyCommand(),
            new MoveCommand(),
            new UniqueListCommand(),
            new UniqueCreateCommand(),
            new UniqueRebuildCommand(),
            new UniqueDropCommand(),
            new UniqueFindCommand(),
            new QueryCommand(),
        ]);

        // Global styles can be further customized in individual commands via Styles
    }
}