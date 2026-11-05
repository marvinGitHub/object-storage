<?php

namespace melia\ObjectStorage\Query;

use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Query\Ast\Select;
use melia\ObjectStorage\Query\Exception\QueryException;

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
 */
final class Oql
{
    public function __construct(private readonly ObjectStorage $storage)
    {
    }

    /**
     * @param array<int|string, mixed> $parameters Values of ? (by position, from 0) and :name parameters.
     * @param bool $dryRun INSERT, UPDATE, DELETE: compute and check everything, return the objects that would be
     *        written, write nothing.
     * @throws QueryException
     */
    public function query(string $query, array $parameters = [], bool $dryRun = false): Result
    {
        $statement = Parser::parse($query);
        $planner = new Planner($parameters);
        $executor = new Executor($this->storage);
        if ($statement instanceof Select) {
            return $executor->run($planner->plan($statement));
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
        return !Parser::parse($query) instanceof Select;
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
}
