<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command;

use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Query\Ast\TransactionControl;
use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Query\Exception\ScriptException;
use melia\ObjectStorage\Query\Oql;
use melia\ObjectStorage\Query\Result;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs an OQL query or a script of several statements separated by semicolons (see docs/query.md) and prints the
 * results as tables, or as JSON with --json. A script ending with an open transaction is rolled back and fails;
 * --preview runs it and rolls everything back.
 *
 *     object-storage query --dir ./data "SELECT name, price FROM Product WHERE price < :max" --param max=10
 *     echo "SELECT COUNT(*) FROM Product" | object-storage query --dir ./data
 *     object-storage query --dir ./data --file prices.oql    # START TRANSACTION; UPDATE …; DELETE …; COMMIT;
 */
#[AsCommand(name: 'query')]
final class QueryCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setDescription('Run an OQL query or script (SELECT … FROM Class …; statements separated by ;) and print the results [experimental]')
            ->addArgument('query', InputArgument::OPTIONAL, 'The query or script; read from --file or STDIN if missing')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Read the query or script from a file')
            ->addOption('param', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A parameter: name=value for :name, value for ? (in order, across the script); numbers, true, false and null as such, "quoted" as text')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'INSERT, UPDATE, DELETE, ALTER: check and list the objects that would be written, write nothing')
            ->addOption('preview', null, InputOption::VALUE_NONE, 'Run everything, show the results, then roll it all back');
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
            $json = (bool)$input->getOption('json');
            $preview = (bool)$input->getOption('preview');
            try {
                $script = (new Oql($storage))->script($query, $parameters, (bool)$input->getOption('dry-run'), preview: $preview);
            } catch (ScriptException $e) {
                // what ran before the failing statement, then the error
                if (!$json) {
                    $this->printResults($e->results, $output, $preview);
                }
                return $this->failure($e->getMessage() . ($e->rolledBack ? ' The transaction was rolled back.' : ''));
            } catch (QueryException $e) {
                return $this->failure($e->getMessage());
            }

            if ($json) {
                $items = array_map(fn(Result $result): array => $this->jsonOf($result, count($script->results) > 1), $script->results);
                $output->writeln((string)json_encode(1 === count($items) ? $items[0] : $items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
            } else {
                $this->printResults($script->results, $output, $preview);
                if ($preview) {
                    $this->io->writeln('Preview: everything was rolled back, nothing was kept.');
                }
            }

            if ($script->rolledBack) {
                return $this->failure('The script ended with an open transaction: it was rolled back, nothing of it was kept. End it with COMMIT.');
            }
            return Command::SUCCESS;
        });
    }

    /**
     * Prints the results of the statements of a script; with several, each under its number.
     *
     * @param list<Result> $results
     */
    private function printResults(array $results, OutputInterface $output, bool $preview): void
    {
        $numbered = count($results) > 1;
        foreach ($results as $index => $result) {
            if ($numbered) {
                $this->io->writeln(sprintf('<comment>Statement %d</comment>', $index + 1));
            }
            $this->printResult($result, $output, $preview);
        }
    }

    private function printResult(Result $result, OutputInterface $output, bool $preview): void
    {
        // START TRANSACTION, COMMIT, ROLLBACK
        if (in_array($result->statement, [TransactionControl::BEGIN, TransactionControl::COMMIT, TransactionControl::ROLLBACK], true)) {
            $this->io->writeln($result->statement . ($preview && TransactionControl::COMMIT === $result->statement ? ' (preview: kept until the end, then rolled back)' : '') . '.');
            return;
        }

        // INSERT, UPDATE, DELETE: how many, and the UUIDs
        if (null !== $result->statement) {
            $done = ['INSERT' => 'inserted', 'UPDATE' => 'updated', 'DELETE' => 'deleted', 'ALTER' => 'changed'][$result->statement] ?? 'written';
            foreach (array_filter($result->column(0)) as $uuid) {
                $output->writeln($uuid);
            }
            $this->io->writeln($result->dryRun
                ? sprintf('Dry run: %d object(s) would be %s, nothing was written.', $result->affected(), $done)
                : sprintf('%d object(s) %s.', $result->affected(), $done));
            return;
        }

        $this->io->table($result->columns, array_map(
            static fn(array $row): array => array_map([self::class, 'cell'], $row),
            $result->rows
        ));
        $this->io->writeln(sprintf('%d row(s)', count($result)));
    }

    /**
     * A result as JSON: the rows of a SELECT (with several statements under "rows", next to "statement": "SELECT"),
     * the statement, the dry run, the number and the UUIDs of a write, the statement of START, COMMIT, ROLLBACK.
     *
     * @return array<int|string, mixed>
     */
    private function jsonOf(Result $result, bool $several): array
    {
        if (null === $result->statement) {
            return $several ? ['statement' => 'SELECT', 'rows' => $result->toArray()] : $result->toArray();
        }
        if (in_array($result->statement, [TransactionControl::BEGIN, TransactionControl::COMMIT, TransactionControl::ROLLBACK], true)) {
            return ['statement' => $result->statement];
        }
        return [
            'statement' => $result->statement,
            'dryRun' => $result->dryRun,
            'affected' => $result->affected(),
            'uuids' => array_values(array_filter($result->column(0))),
        ];
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
