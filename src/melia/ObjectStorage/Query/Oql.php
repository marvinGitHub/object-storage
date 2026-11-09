<?php

namespace melia\ObjectStorage\Query;

use melia\ObjectStorage\Exception\Exception;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Query\Ast\Select;
use melia\ObjectStorage\Query\Ast\TransactionControl;
use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Query\Exception\ScriptException;
use melia\ObjectStorage\Search\Index\ManagerInterface;
use melia\ObjectStorage\Transaction;
use melia\ObjectStorage\Unique\Manager as UniqueManager;
use Throwable;

/**
 * Queries and changes the objects of a storage with an SQL-like language (see docs/query.md):
 *
 *     $oql = new Oql($storage);
 *     $result = $oql->query(
 *         'SELECT c.title AS category, COUNT(*) AS products, AVG(p.price) AS price
 *          FROM Product p JOIN Category c ON c = p.category
 *          WHERE p.active = :active GROUP BY c.title ORDER BY products DESC LIMIT 10',
 *         ['active' => true]
 *     );
 *     foreach ($result as $row) { echo $row['category'], ': ', $row['products'], "\n"; }
 *
 *     $oql->query('UPDATE Product SET price = price * 1.1 WHERE category.title = ?', ['Fruit'])->affected();
 *
 * SELECT with JOIN (INNER, LEFT), WHERE, GROUP BY, HAVING, aggregates, ORDER BY, LIMIT and OFFSET; INSERT, UPDATE,
 * DELETE and ALTER CLASS … DROP ATTRIBUTE (ALTER TABLE … DROP COLUMN). Paths lead into referenced objects (p.category.title), UUID is the UUID of an object; values in
 * queries are best passed as parameters (? or :name), which are never read as part of the query.
 *
 * Objects are read one after another and only what the query needs is kept, so large classes don't fill the
 * memory. With UUID = '…', a unique index or (with the search manager) a search index on a filterable string field,
 * only the objects the index names are read; EXPLAIN SELECT … tells which way.
 *
 * An Oql instance is a connection: START TRANSACTION (or BEGIN) starts a transaction on its storage instance, which
 * COMMIT or ROLLBACK ends; meanwhile every change through that storage instance is part of it. Every writing
 * statement is atomic: if it fails, what it wrote is rolled back (in a transaction only that statement, which stays
 * open, as in MySQL). script() runs several statements separated by semicolons.
 *
 * @experimental OQL is experimental in 1.x: its syntax, semantics and this API may change in a minor release (see docs/query.md).
 */
final class Oql
{
    /** the transaction started with START TRANSACTION, until COMMIT or ROLLBACK */
    private ?Transaction $transaction = null;

    /**
     * @param ManagerInterface|null $search Search indexes (Loupe) to find objects by filterable string fields.
     * @param UniqueManager|null $unique Unique indexes (by default those of the storage).
     * @param bool $useIndexes Whether to use indexes at all (false: always read all objects of a class).
     */
    public function __construct(
        private readonly ObjectStorage     $storage,
        private readonly ?ManagerInterface $search = null,
        private readonly ?UniqueManager    $unique = null,
        private readonly bool              $useIndexes = true,
    )
    {
    }

    /**
     * A transaction left open is rolled back, as when a database connection is closed.
     */
    public function __destruct()
    {
        if ($this->inTransaction()) {
            try {
                $this->transaction?->rollback();
            } catch (Throwable $e) {
                $this->storage->getLogger()?->log(new Exception(message: 'Rolling back the open transaction of an OQL connection failed', previous: $e));
            }
        }
    }

    /**
     * Runs a statement. EXPLAIN SELECT … returns how the objects of each source are found instead of the rows.
     * START TRANSACTION, COMMIT and ROLLBACK return an empty result naming the statement.
     *
     * @param array<int|string, mixed> $parameters Values of ? (by position, from 0) and :name parameters.
     * @param bool $dryRun INSERT, UPDATE, DELETE, ALTER: compute and check everything, return the objects that would be
     *        written, write nothing.
     * @param int|null $maxRows SELECT: return at most this many rows; Result::$truncated tells whether there were
     *        more.
     * @throws QueryException
     */
    public function query(string $query, array $parameters = [], bool $dryRun = false, ?int $maxRows = null): Result
    {
        $explain = self::explains($query);
        if ($explain) {
            // replaced by as many spaces, so positions in errors stay right
            $query = (string)preg_replace('/^(\s*)EXPLAIN\b/i', '$1       ', $query, 1);
        }
        $statement = Parser::parse($query);
        if ($statement instanceof TransactionControl) {
            if ($explain) {
                throw new QueryException('EXPLAIN takes a SELECT');
            }
            return $this->control($statement);
        }
        $planner = new Planner($parameters);
        $executor = new Executor($this->storage, $this->search, $this->unique, $this->useIndexes);
        if ($explain) {
            if (!$statement instanceof Select) {
                throw new QueryException('EXPLAIN takes a SELECT');
            }
            return $executor->explain($planner->plan($statement));
        }
        if ($statement instanceof Select) {
            return $executor->run($planner->plan($statement), $maxRows);
        }

        $plan = $planner->planWrite($statement);
        $writer = new Writer($this->storage, $executor);
        if ($dryRun) {
            return $writer->run($plan, true);
        }
        return $this->atomically(static fn(): Result => $writer->run($plan));
    }

    /**
     * Runs a script: statements separated by semicolons (outside strings, quoted names and comments), one after
     * another, until one fails. ? parameters are numbered across the script.
     *
     * A transaction the script starts and leaves open is rolled back at its end (ScriptResult::$rolledBack), as is
     * one it started when a statement fails.
     *
     * @param array<int|string, mixed> $parameters Values of ? (by position across the script) and :name parameters.
     * @param bool $dryRun Writing statements only compute and check, see query().
     * @param int|null $maxRows For each SELECT, see query().
     * @param bool $preview Run everything and roll it all back at the end: shows the results of a script, including
     *        its writes, without keeping them. START TRANSACTION, COMMIT and ROLLBACK work within the preview (a
     *        COMMIT keeps the changes until the end, a ROLLBACK undoes them back to the START). Not in a transaction.
     * @throws ScriptException If a statement fails; it says which one and holds the results before it.
     * @throws QueryException If the script holds no statement or can't be read (e.g. an unterminated string).
     */
    public function script(string $script, array $parameters = [], bool $dryRun = false, ?int $maxRows = null, bool $preview = false): ScriptResult
    {
        $statements = Parser::splitScript($script);

        $outer = null;
        if ($preview) {
            if ($this->inTransaction() || null !== $this->storage->getActiveTransaction()) {
                throw new QueryException('A preview can\'t run in a transaction: COMMIT or ROLLBACK it first');
            }
            $outer = $this->begin();
        }

        $results = [];
        $started = false; // whether the script started the transaction that is open
        $previewStart = null; // in a preview: the savepoint of its START TRANSACTION

        foreach ($statements as $index => $range) {
            $query = self::isolate($script, $range['start'], $range['end']);
            try {
                if (null !== $outer) {
                    $control = self::transactionControl($query);
                    if (null !== $control) {
                        $results[] = $this->previewControl($control, $outer, $previewStart);
                        continue;
                    }
                }

                $before = $this->inTransaction();
                $results[] = $this->query($query, self::shift($parameters, $range['positional']), $dryRun, $maxRows);
                $after = $this->inTransaction();
                if ($before !== $after) {
                    $started = $after;
                }
            } catch (QueryException $e) {
                $rolledBack = false;
                if (null !== $outer) {
                    $rolledBack = $this->end($outer);
                } elseif ($started && $this->inTransaction()) {
                    $rolledBack = $this->end($this->transaction);
                }
                $message = count($statements) > 1 ? sprintf('Statement %d: %s', $index + 1, $e->getMessage()) : $e->getMessage();
                throw new ScriptException($message, $e->position, $index, $results, $rolledBack, $e);
            }
        }

        $rolledBack = false;
        if (null !== $outer) {
            $this->end($outer);
        } elseif ($started && $this->inTransaction()) {
            $rolledBack = $this->end($this->transaction);
        }

        return new ScriptResult($results, $rolledBack, $preview);
    }

    /**
     * Whether a transaction started with START TRANSACTION is open.
     */
    public function inTransaction(): bool
    {
        return true === $this->transaction?->isActive();
    }

    /**
     * Whether a query or script writes (INSERT, UPDATE, DELETE, ALTER) or controls a transaction, e.g. to ask
     * before running it. A statement that can't be parsed writes nothing: its error is reported when the query or
     * script runs, with its place in the script and the results of the statements before it.
     */
    public static function writes(string $query): bool
    {
        try {
            $statements = Parser::splitScript($query);
        } catch (QueryException) {
            return false;
        }

        foreach ($statements as $range) {
            $statement = self::isolate($query, $range['start'], $range['end']);
            try {
                if (!self::explains($statement) && !Parser::parse($statement) instanceof Select) {
                    return true;
                }
            } catch (QueryException) {
                // can't run, so it doesn't write
            }
        }
        return false;
    }

    /**
     * Parses and binds a SELECT without running it, e.g. to check it.
     *
     * @throws QueryException
     */
    public function plan(string $query, array $parameters = []): Plan
    {
        $statement = Parser::parse($query);
        if (!$statement instanceof Select) {
            throw new QueryException('plan() takes a SELECT');
        }
        return (new Planner($parameters))->plan($statement);
    }

    /**
     * START TRANSACTION, COMMIT, ROLLBACK. COMMIT and ROLLBACK without a transaction do nothing, as in MySQL.
     *
     * @throws QueryException
     */
    private function control(TransactionControl $statement): Result
    {
        switch ($statement->action) {
            case TransactionControl::BEGIN:
                if ($this->inTransaction()) {
                    // MySQL commits the open transaction implicitly; a mistake is more likely than the intention
                    throw new QueryException('A transaction is already active: COMMIT or ROLLBACK it first');
                }
                $this->transaction = $this->begin();
                break;

            case TransactionControl::COMMIT:
            case TransactionControl::ROLLBACK:
                $transaction = $this->transaction;
                $this->transaction = null;
                if (null !== $transaction && $transaction->isActive()) {
                    try {
                        TransactionControl::COMMIT === $statement->action ? $transaction->commit() : $transaction->rollback();
                    } catch (Throwable $e) {
                        throw new QueryException(sprintf('%s failed: %s', $statement->action, $e->getMessage()), null, $e);
                    }
                }
                break;
        }

        return new Result([], [], $statement->action);
    }

    /**
     * Runs a writing statement atomically: in a transaction (of this connection, or one running on the storage
     * instance) back to a savepoint if it fails, which leaves the transaction open; otherwise in a transaction of
     * its own.
     *
     * @param callable(): Result $write
     * @throws QueryException
     */
    private function atomically(callable $write): Result
    {
        $transaction = $this->inTransaction() ? $this->transaction : $this->storage->getActiveTransaction();
        if (null !== $transaction && $transaction->isActive()) {
            $savepoint = $this->savepoint($transaction);
            try {
                return $write();
            } catch (Throwable $e) {
                $wrote = $this->savepoint($transaction) > $savepoint;
                try {
                    $transaction->rollbackTo($savepoint);
                } catch (Throwable $rollbackError) {
                    throw new QueryException(sprintf('%s; rolling back the statement failed, the transaction was ended and safe mode enabled: %s', self::message($e), $rollbackError->getMessage()), self::position($e), $rollbackError);
                }
                throw self::failed($e, $wrote, 'the statement was rolled back, the transaction is still open');
            }
        }

        $transaction = $this->begin();
        $savepoint = $this->savepoint($transaction);
        try {
            $result = $write();
            $transaction->commit();
            return $result;
        } catch (Throwable $e) {
            $wrote = $transaction->isActive() && $this->savepoint($transaction) > $savepoint;
            try {
                if ($transaction->isActive()) {
                    $transaction->rollback();
                }
            } catch (Throwable $rollbackError) {
                throw new QueryException(sprintf('%s; rolling back the statement failed, safe mode was enabled: %s', self::message($e), $rollbackError->getMessage()), self::position($e), $rollbackError);
            }
            throw self::failed($e, $wrote, 'the statement was rolled back, nothing was written');
        }
    }

    /**
     * The error of a failed writing statement; if it had written objects, it says they were rolled back.
     */
    private static function failed(Throwable $e, bool $wrote, string $rolledBack): QueryException
    {
        if (!$wrote && $e instanceof QueryException) {
            return $e;
        }
        return new QueryException(self::message($e) . ($wrote ? '; ' . $rolledBack : ''), self::position($e), $e);
    }

    /**
     * In a preview: START TRANSACTION marks a savepoint, ROLLBACK goes back to it, COMMIT keeps the changes until the
     * end of the preview.
     *
     * @throws QueryException
     */
    private function previewControl(TransactionControl $control, Transaction $outer, ?int &$start): Result
    {
        switch ($control->action) {
            case TransactionControl::BEGIN:
                if (null !== $start) {
                    throw new QueryException('A transaction is already active: COMMIT or ROLLBACK it first');
                }
                $start = $this->savepoint($outer);
                break;
            case TransactionControl::COMMIT:
                $start = null;
                break;
            case TransactionControl::ROLLBACK:
                if (null !== $start) {
                    try {
                        $outer->rollbackTo($start);
                    } catch (Throwable $e) {
                        throw new QueryException('ROLLBACK failed: ' . $e->getMessage(), null, $e);
                    }
                    $start = null;
                }
                break;
        }

        return new Result([], [], $control->action, dryRun: true);
    }

    /**
     * @throws QueryException
     */
    private function begin(): Transaction
    {
        try {
            return (new Transaction($this->storage))->begin();
        } catch (Throwable $e) {
            throw new QueryException('The transaction can\'t be started: ' . $e->getMessage(), null, $e);
        }
    }

    /**
     * @throws QueryException
     */
    private function savepoint(Transaction $transaction): int
    {
        try {
            return $transaction->savepoint();
        } catch (Throwable $e) {
            throw new QueryException('The transaction can\'t be read: ' . $e->getMessage(), null, $e);
        }
    }

    /**
     * Rolls a transaction back at the end of a script; a failure is logged (safe mode is enabled by it).
     *
     * @return bool Whether it was rolled back.
     */
    private function end(?Transaction $transaction): bool
    {
        if ($transaction === $this->transaction) {
            $this->transaction = null;
        }
        if (null === $transaction || !$transaction->isActive()) {
            return false;
        }

        try {
            $transaction->rollback();
            return true;
        } catch (Throwable $e) {
            $this->storage->getLogger()?->log(new Exception(message: 'Rolling back the transaction of a script failed', previous: $e));
            return false;
        }
    }

    /**
     * The statement of a script at its place: everything before it blanked (line breaks kept), so positions and
     * lines in errors are those in the script.
     */
    private static function isolate(string $script, int $start, int $end): string
    {
        return preg_replace('/[^\n]/', ' ', substr($script, 0, $start)) . substr($script, $start, $end - $start);
    }

    /**
     * The parameters of a statement of a script: ? are numbered across the script, so those of a statement start
     * after the ones before it.
     *
     * @param array<int|string, mixed> $parameters
     * @return array<int|string, mixed>
     */
    private static function shift(array $parameters, int $positionalBefore): array
    {
        if (0 === $positionalBefore) {
            return $parameters;
        }

        $shifted = [];
        foreach ($parameters as $key => $value) {
            if (is_string($key)) {
                $shifted[$key] = $value;
            } elseif ($key >= $positionalBefore) {
                $shifted[$key - $positionalBefore] = $value;
            }
        }
        return $shifted;
    }

    /**
     * The statement as START TRANSACTION, COMMIT or ROLLBACK, null for other statements.
     *
     * @throws QueryException
     */
    private static function transactionControl(string $query): ?TransactionControl
    {
        if (self::explains($query)) {
            return null;
        }
        $statement = Parser::parse($query);
        return $statement instanceof TransactionControl ? $statement : null;
    }

    private static function message(Throwable $e): string
    {
        return $e instanceof QueryException || null === $e->getPrevious() ? $e->getMessage() : $e->getMessage() . ': ' . $e->getPrevious()->getMessage();
    }

    private static function position(Throwable $e): ?int
    {
        return $e instanceof QueryException ? $e->position : null;
    }

    private static function explains(string $query): bool
    {
        return 1 === preg_match('/^\s*EXPLAIN\b/i', $query);
    }
}
