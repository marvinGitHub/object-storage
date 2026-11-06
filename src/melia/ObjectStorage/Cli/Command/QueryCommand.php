<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command;

use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Query\Oql;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs an OQL query (see docs/query.md) and prints the result as a table, or as JSON with --json.
 *
 *     object-storage query --dir ./data "SELECT name, price FROM Product WHERE price < :max" --param max=10
 *     echo "SELECT COUNT(*) FROM Product" | object-storage query --dir ./data
 */
#[AsCommand(name: 'query')]
final class QueryCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setDescription('Run an OQL query (SELECT … FROM Class …) and print the result [experimental]')
            ->addArgument('query', InputArgument::OPTIONAL, 'The query; read from --file or STDIN if missing')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Read the query from a file')
            ->addOption('param', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A parameter: name=value for :name, value for ? (in order); numbers, true, false and null as such, "quoted" as text')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'INSERT, UPDATE, DELETE: check and list the objects that would be written, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        $query = $input->getArgument('query');
        if (null === $query && null !== ($file = $input->getOption('file'))) {
            $query = @file_get_contents((string)$file);
            if (false === $query) {
                return $this->failure(sprintf('Unable to read %s.', $file));
            }
        }
        if (null === $query) {
            $query = stream_get_contents(STDIN);
        }
        if (!is_string($query) || '' === trim($query)) {
            return $this->failure('No query: pass it as argument, with --file or on STDIN.');
        }

        $parameters = [];
        foreach ((array)$input->getOption('param') as $param) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/s', (string)$param, $match)) {
                $parameters[$match[1]] = self::value($match[2]);
            } else {
                $parameters[] = self::value((string)$param);
            }
        }

        return $this->withStorage($input, function (ObjectStorage $storage) use ($input, $output, $query, $parameters): int {
            try {
                $result = (new Oql($storage))->query($query, $parameters, (bool)$input->getOption('dry-run'));
            } catch (QueryException $e) {
                return $this->failure($e->getMessage());
            }

            // INSERT, UPDATE, DELETE: how many, and the UUIDs
            if (null !== $result->statement) {
                $done = ['INSERT' => 'inserted', 'UPDATE' => 'updated', 'DELETE' => 'deleted'][$result->statement];
                if ($input->getOption('json')) {
                    $output->writeln((string)json_encode([
                        'statement' => $result->statement,
                        'dryRun' => $result->dryRun,
                        'affected' => $result->affected(),
                        'uuids' => array_values(array_filter($result->column(0))),
                    ], JSON_UNESCAPED_SLASHES));
                    return Command::SUCCESS;
                }
                foreach (array_filter($result->column(0)) as $uuid) {
                    $output->writeln($uuid);
                }
                $this->io->writeln($result->dryRun
                    ? sprintf('Dry run: %d object(s) would be %s, nothing was written.', $result->affected(), $done)
                    : sprintf('%d object(s) %s.', $result->affected(), $done));
                return Command::SUCCESS;
            }

            if ($input->getOption('json')) {
                $output->writeln((string)json_encode($result->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
                return Command::SUCCESS;
            }

            $this->io->table($result->columns, array_map(
                static fn(array $row): array => array_map([self::class, 'cell'], $row),
                $result->rows
            ));
            $this->io->writeln(sprintf('%d row(s)', count($result)));
            return Command::SUCCESS;
        });
    }

    /** a parameter as typed: numbers, true, false and null as such, "quoted" as the text inside */
    private static function value(string $text): mixed
    {
        return match (true) {
            'null' === $text => null,
            'true' === $text => true,
            'false' === $text => false,
            (bool)preg_match('/^-?\d+$/', $text) => (int)$text,
            is_numeric($text) => (float)$text,
            (bool)preg_match('/^"(.*)"$/s', $text, $match) => $match[1],
            default => $text,
        };
    }

    private static function cell(mixed $value): string
    {
        return match (true) {
            null === $value => 'NULL',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            is_float($value) => (string)json_encode($value, JSON_PRESERVE_ZERO_FRACTION),
            default => (string)$value,
        };
    }
}
