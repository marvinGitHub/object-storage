<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SafeModeCommand extends BaseCommand
{
    protected static $defaultName = 'safemode';

    protected function configure(): void
    {
        parent::configure();
        $this
            ->setDescription('Enable, disable or check safemode')
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Enable safemode')
            ->addOption('disable', null, InputOption::VALUE_NONE, 'Disable safemode')
            ->addOption('toggle', null, InputOption::VALUE_NONE, 'Toggle safemode')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Show current safemode status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        $enable = (bool)$input->getOption('enable');
        $disable = (bool)$input->getOption('disable');
        $toggle = (bool)$input->getOption('toggle');
        $status = (bool)$input->getOption('status');

        $selected = (int)$enable + (int)$disable + (int)$toggle + (int)$status;
        if ($selected === 0) {
            return $this->failure('No action specified. Use one of --enable, --disable, --toggle, --status', 2);
        }
        if ($selected > 1) {
            return $this->failure('Invalid combination. Use only one of --enable, --disable, --toggle, --status', 2);
        }

        return $this->withStorage($input, function ($storage) use ($enable, $disable, $toggle, $status) {
            $state = $storage->getStateHandler();

            if ($status) {
                $enabled = $state->safeModeEnabled();
                return $enabled
                    ? $this->success('Safemode is ENABLED')
                    : $this->success('Safemode is DISABLED');
            }

            if ($enable) {
                $state->enableSafeMode();
            } else if ($disable) {
                $state->disableSafeMode();
            } else if ($toggle) {
                $state->safeModeEnabled() ? $state->disableSafeMode() : $state->enableSafeMode();
            }

            $enabled = $state->safeModeEnabled();
            return $enabled
                ? $this->success('Safemode ENABLED')
                : $this->success('Safemode DISABLED');
        });
    }
}