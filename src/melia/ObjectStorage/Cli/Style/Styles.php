<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Style;

use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class Styles
{
    public static function io(InputInterface $input, OutputInterface $output): SymfonyStyle
    {
        self::register($output);
        return new SymfonyStyle($input, $output);
    }

    public static function register(OutputInterface $output): void
    {
        $formatter = $output->getFormatter();

        // Define custom color styles
        if (!$formatter->hasStyle('ok')) {
            $formatter->setStyle('ok', new OutputFormatterStyle('green', null, ['bold']));
        }
        if (!$formatter->hasStyle('warn')) {
            $formatter->setStyle('warn', new OutputFormatterStyle('yellow', null, ['bold']));
        }
        if (!$formatter->hasStyle('err')) {
            $formatter->setStyle('err', new OutputFormatterStyle('red', null, ['bold']));
        }
        if (!$formatter->hasStyle('muted')) {
            $formatter->setStyle('muted', new OutputFormatterStyle('cyan', null, []));
        }
        if (!$formatter->hasStyle('title')) {
            $formatter->setStyle('title', new OutputFormatterStyle('white', 'blue', ['bold']));
        }
        if (!$formatter->hasStyle('key')) {
            $formatter->setStyle('key', new OutputFormatterStyle('magenta', null, ['bold']));
        }
        if (!$formatter->hasStyle('value')) {
            $formatter->setStyle('white', new OutputFormatterStyle('white', null, []));
        }
    }
}