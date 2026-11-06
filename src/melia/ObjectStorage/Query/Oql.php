<?php

namespace melia\ObjectStorage\Query;

use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Query\Ast\Select;
use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Search\Index\ManagerInterface;
use melia\ObjectStorage\Unique\Manager as UniqueManager;

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
 * SELECT with JOIN (INNER, LEFT), WHERE, GROUP BY, HAVING, aggregates, ORDER BY, LIMIT and OFFSET; INSERT, UPDATE
 * and DELETE. Paths lead into referenced objects (p.category.title), UUID is the UUID of an object; values in
 * queries are best passed as parameters (? or :name), which are never read as part of the query.
 *
 * Objects are read one after another and only what the query needs is kept, so large classes don't fill the
 * memory. With UUID = '…', a unique index or (with the search manager) a search index on a filterable string field,
 * only the objects the index names are read; EXPLAIN SELECT … tells which way.
 */
final class Oql
{
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
     * EXPLAIN SELECT … returns how the objects of each source are found instead of the rows.
     *
     * @param array<int|string, mixed> $parameters Values of ? (by position, from 0) and :name parameters.
     * @param bool $dryRun INSERT, UPDATE, DELETE: compute and check everything, return the objects that would be
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
        return (new Writer($this->storage, $executor))->run($planner->planWrite($statement), $dryRun);
    }

    /**
     * Whether a query writes (INSERT, UPDATE, DELETE), e.g. to ask before running it.
     *
     * @throws QueryException
     */
    public static function writes(string $query): bool
    {
        return !self::explains($query) && !Parser::parse($query) instanceof Select;
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

    private static function explains(string $query): bool
    {
        return 1 === preg_match('/^\s*EXPLAIN\b/i', $query);
    }
}
