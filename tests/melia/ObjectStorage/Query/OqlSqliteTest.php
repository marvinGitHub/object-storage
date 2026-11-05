<?php

namespace Tests\melia\ObjectStorage\Query;

use melia\ObjectStorage\Query\Oql;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\melia\ObjectStorage\TestCase;
use Throwable;

/**
 * OQL gives the results of SQL: random people (with friends) and companies are stored in the object storage and in
 * SQLite, random queries are written in both languages (joins, implicit joins by paths, WHERE with three-valued
 * logic, GROUP BY, aggregates, HAVING, DISTINCT, ORDER BY, LIMIT, OFFSET), and their results compared.
 *
 * Paths into referenced objects (p.company.name) are scalar subqueries in SQL, a JOIN ON c = p.company compares
 * with the UUID of c; booleans are 1 and 0 in SQLite. The data is ASCII, as SQLite's LOWER, UPPER and LIKE ignore
 * case of ASCII letters only (OQL of all), and numbers are compared with a tolerance.
 */
class OqlSqliteTest extends TestCase
{
    private const array NAMES = ['Anna', 'anna', 'Bob', "O'Neill", 'Carl', 'Dora', 'Emil', 'Eve', '50%', 'a_b', 'Zoe', 'b\\c', ''];
    private const array CITIES = ['Berlin', 'Bonn', 'berlin', 'Hamburg', 'Munich', "Val d'Or"];
    private const array COUNTRIES = ['DE', 'AT', 'CH', 'de'];
    private const array COMPANIES = ['Acme', 'Globex', 'Initech', 'Umbrella', 'acme', 'Stark'];

    private PDO $sql;
    private Oql $oql;
    private int $seed;

    private function random(int $max): int
    {
        return mt_rand(0, $max - 1);
    }

    private function pick(array $list): mixed
    {
        return $list[$this->random(count($list))];
    }

    private function chance(float $p): bool
    {
        return mt_rand() / mt_getrandmax() < $p;
    }

    private function seed(int $seed): void
    {
        $this->seed = $seed;
        mt_srand($seed);
        $this->sql = new PDO('sqlite::memory:');
        $this->sql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // numbers as numbers, not as strings
        $this->sql->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
        // no declared types: values stay as they are, as in the object storage
        $this->sql->exec('CREATE TABLE company (uuid, name, country, size)');
        $this->sql->exec('CREATE TABLE person (uuid, name, age, score, active, city, company, friend)');

        $maybe = fn(callable $value, float $null = 0.15): mixed => $this->chance($null) ? null : $value();
        $companies = [];
        for ($i = 0; $i < 7; $i++) {
            $companies[] = new Company(
                $maybe(fn() => $this->pick(self::COMPANIES), 0.1),
                $maybe(fn() => $this->pick(self::COUNTRIES)),
                $maybe(fn() => $this->random(60)),
            );
        }
        $people = [];
        for ($i = 0; $i < 45; $i++) {
            $people[] = new Person(
                $maybe(fn() => $this->pick(self::NAMES), 0.08),
                $maybe(fn() => $this->random(95) - 5),
                $maybe(fn() => $this->chance(0.5) ? $this->random(20) - 5 : ($this->random(200) - 50) / 4),
                $maybe(fn() => $this->chance(0.5)),
                $maybe(fn() => $this->pick(self::CITIES)),
                $maybe(fn() => $this->pick($companies), 0.25),
            );
        }
        foreach ($people as $person) {
            if ($this->chance(0.6)) {
                $person->friend = $this->pick($people);
            }
        }

        $uuids = new \SplObjectStorage();
        foreach ($companies as $company) {
            $uuids[$company] = $this->storage->store($company);
        }
        foreach ($people as $person) {
            $uuids[$person] = $this->storage->store($person);
        }
        // bound by type: execute([...]) would bind all as text, which SQLite compares as greater than any number
        $insert = $this->sql->prepare('INSERT INTO company VALUES (?, ?, ?, ?)');
        foreach ($companies as $company) {
            $insert->bindValue(1, $uuids[$company]);
            $insert->bindValue(2, $company->name);
            $insert->bindValue(3, $company->country);
            $insert->bindValue(4, $company->size, null === $company->size ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $insert->execute();
        }
        $insert = $this->sql->prepare('INSERT INTO person VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($people as $person) {
            $insert->bindValue(1, $uuids[$person]);
            $insert->bindValue(2, $person->name);
            $insert->bindValue(3, $person->age, null === $person->age ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $insert->bindValue(4, $person->score, match (true) {
                null === $person->score => PDO::PARAM_NULL,
                is_int($person->score) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            });
            $insert->bindValue(5, null === $person->active ? null : (int)$person->active, null === $person->active ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $insert->bindValue(6, $person->city);
            $insert->bindValue(7, null === $person->company ? null : $uuids[$person->company]);
            $insert->bindValue(8, null === $person->friend ? null : $uuids[$person->friend]);
            $insert->execute();
        }
        // scores are numbers in SQLite too (bound as text above to keep floats exact)
        $this->sql->exec('UPDATE person SET score = CAST(score AS REAL) WHERE typeof(score) = \'text\'');

        $this->oql = new Oql($this->storage);
    }

    // ---- random queries: [oql, sql] ----

    /** @var list<string> the aliases of the query being written: p, and c and f if joined */
    private array $aliases = [];

    /**
     * Writing: no paths into other people (p.friend.…). SQLite evaluates them while updating the table, row by row,
     * OQL (as the SQL standard) on the state before the statement.
     */
    private bool $writing = false;

    private function withoutFriends(array $columns): array
    {
        return $this->writing ? array_values(array_filter($columns, static fn(array $c): bool => !str_contains($c[0], 'p.friend'))) : $columns;
    }

    /** a number column: [oql, sql] */
    private function numberColumn(): array
    {
        $columns = [['p.age', 'p.age'], ['p.score', 'p.score'],
            ['p.company.size', '(SELECT size FROM company WHERE uuid = p.company)'],
            ['p.friend.age', '(SELECT age FROM person WHERE uuid = p.friend)']];
        if (in_array('c', $this->aliases, true)) {
            $columns[] = ['c.size', 'c.size'];
        }
        if (in_array('f', $this->aliases, true)) {
            $columns[] = ['f.age', 'f.age'];
            $columns[] = ['f.score', 'f.score'];
        }
        return $this->pick($this->withoutFriends($columns));
    }

    private function stringColumn(): array
    {
        $columns = [['p.name', 'p.name'], ['p.city', 'p.city'],
            ['p.company.name', '(SELECT name FROM company WHERE uuid = p.company)'],
            ['p.friend.name', '(SELECT name FROM person WHERE uuid = p.friend)'],
            ['p.friend.city', '(SELECT city FROM person WHERE uuid = p.friend)']];
        if (in_array('c', $this->aliases, true)) {
            $columns[] = ['c.name', 'c.name'];
            $columns[] = ['c.country', 'c.country'];
        }
        if (in_array('f', $this->aliases, true)) {
            $columns[] = ['f.name', 'f.name'];
        }
        return $this->pick($this->withoutFriends($columns));
    }

    private function booleanColumn(): array
    {
        $columns = [['p.active', 'p.active'], ['p.friend.active', '(SELECT active FROM person WHERE uuid = p.friend)']];
        if (in_array('f', $this->aliases, true)) {
            $columns[] = ['f.active', 'f.active'];
        }
        return $this->pick($this->withoutFriends($columns));
    }

    private function numberLiteral(): array
    {
        $number = $this->chance(0.7) ? (string)($this->random(80) - 10) : sprintf('%.2f', ($this->random(400) - 100) / 8);
        return [$number, $number];
    }

    private function stringLiteral(): array
    {
        $text = $this->pick([...self::NAMES, ...self::CITIES, ...self::COUNTRIES, ...self::COMPANIES, 'x', 'Berl']);
        $quoted = "'" . str_replace("'", "''", $text) . "'";
        return [$quoted, $quoted];
    }

    private function number(int $depth = 0): array
    {
        if ($depth > 1) {
            return $this->chance(0.7) ? $this->numberColumn() : $this->numberLiteral();
        }
        [$a, $as] = $this->number($depth + 1);
        [$b, $bs] = $this->number($depth + 1);
        return match ($this->random(9)) {
            0 => ["($a + $b)", "($as + $bs)"],
            1 => ["($a - $b)", "($as - $bs)"],
            2 => ["($a * $b)", "($as * $bs)"],
            // OQL divides as MySQL: 7 / 2 = 3.5
            3 => ["($a / $b)", "(CAST($as AS REAL) / $bs)"],
            4 => ["ABS($a)", "ABS($as)"],
            5 => ["COALESCE($a, $b)", "COALESCE($as, $bs)"],
            // in parentheses: --8 would be a comment
            6 => ["-($a)", "-($as)"],
            7 => (function () use ($depth): array {
                [$s, $ss] = $this->string($depth + 1);
                return ["LENGTH($s)", "LENGTH($ss)"];
            })(),
            default => $this->numberColumn(),
        };
    }

    private function string(int $depth = 0): array
    {
        if ($depth > 1) {
            return $this->chance(0.75) ? $this->stringColumn() : $this->stringLiteral();
        }
        [$a, $as] = $this->string($depth + 1);
        return match ($this->random(7)) {
            0 => ["LOWER($a)", "LOWER($as)"],
            1 => ["UPPER($a)", "UPPER($as)"],
            2 => (function () use ($a, $as, $depth): array {
                [$b, $bs] = $this->string($depth + 1);
                return ["($a || $b)", "($as || $bs)"];
            })(),
            3 => (function () use ($a, $as): array {
                [$b, $bs] = $this->stringLiteral();
                return ["COALESCE($a, $b)", "COALESCE($as, $bs)"];
            })(),
            4 => ["TRIM($a)", "TRIM($as, ' ')"],
            default => $this->stringColumn(),
        };
    }

    private function likePattern(): string
    {
        $source = $this->pick([...self::NAMES, ...self::CITIES, ...self::COMPANIES]);
        $pattern = '';
        foreach (str_split($source) as $c) {
            $r = $this->random(10);
            $pattern .= match (true) {
                0 === $r => '_',
                1 === $r => '%',
                in_array($c, ['%', '_', '\\'], true) => '\\' . $c,
                default => $this->chance(0.3) ? strtoupper($c) : $c,
            };
        }
        if ($this->chance(0.4)) {
            $pattern = '%' . $pattern;
        }
        if ($this->chance(0.4)) {
            $pattern .= '%';
        }
        return "'" . str_replace("'", "''", $pattern) . "'";
    }

    private function predicate(int $depth = 0): array
    {
        $kind = $this->random($depth < 2 ? 12 : 8);
        $not = $this->chance(0.3);
        switch ($kind) {
            case 0:
            case 1:
                [$a, $as] = $this->number();
                [$b, $bs] = $this->chance(0.6) ? $this->numberLiteral() : $this->number();
                $op = $this->pick(['=', '!=', '<', '<=', '>', '>=']);
                return ["$a $op $b", "$as $op $bs"];
            case 2:
                [$a, $as] = $this->string();
                [$b, $bs] = $this->chance(0.7) ? $this->stringLiteral() : $this->string();
                $op = $this->pick(['=', '!=', '<', '>', '<>']);
                return ["$a $op $b", "$as $op $bs"];
            case 3:
                [$a, $as] = $this->pick([$this->number(), $this->string(), $this->booleanColumn()]);
                $is = $not ? 'IS NOT NULL' : 'IS NULL';
                return ["$a $is", "$as $is"];
            case 4:
                $string = $this->chance(0.5);
                [$a, $as] = $string ? $this->string() : $this->number();
                $values = [];
                for ($i = 0, $n = 1 + $this->random(4); $i < $n; $i++) {
                    $values[] = $this->chance(0.12) ? ['NULL', 'NULL'] : ($string ? $this->stringLiteral() : $this->numberLiteral());
                }
                $in = $not ? 'NOT IN' : 'IN';
                return ["$a $in (" . implode(', ', array_column($values, 0)) . ')', "$as $in (" . implode(', ', array_column($values, 1)) . ')'];
            case 5:
                [$a, $as] = $this->number();
                [$low] = $this->numberLiteral();
                [$high] = $this->numberLiteral();
                $between = $not ? 'NOT BETWEEN' : 'BETWEEN';
                return ["$a $between $low AND $high", "$as $between $low AND $high"];
            case 6:
                [$a, $as] = $this->string();
                $pattern = $this->likePattern();
                $like = $not ? 'NOT LIKE' : 'LIKE';
                return ["$a $like $pattern", "$as $like $pattern ESCAPE '\\'"];
            case 7:
                [$a, $as] = $this->booleanColumn();
                return $this->pick([[$a, $as], ["$a = TRUE", "$as = TRUE"], ["$a = FALSE", "$as = FALSE"], ["NOT $a", "NOT $as"]]);
            case 8:
                [$a, $as] = $this->predicate($depth + 1);
                return ["NOT ($a)", "NOT ($as)"];
            default:
                [$a, $as] = $this->predicate($depth + 1);
                [$b, $bs] = $this->predicate($depth + 1);
                $op = $this->pick(['AND', 'OR']);
                return ["($a) $op ($b)", "($as) $op ($bs)"];
        }
    }

    /** FROM with a join: [oql, sql] */
    private function from(): array
    {
        $this->aliases = ['p'];
        switch ($this->random(6)) {
            case 0:
            case 1:
                $this->aliases[] = 'c';
                $left = $this->chance(0.5) ? 'LEFT JOIN' : 'JOIN';
                $extra = $this->chance(0.3) ? ' AND c.size > ' . $this->random(40) : '';
                return ["FROM Person p $left Company c ON c = p.company$extra", "FROM person p $left company c ON c.uuid = p.company$extra"];
            case 2:
                $this->aliases[] = 'f';
                $left = $this->chance(0.5) ? 'LEFT JOIN' : 'JOIN';
                return ["FROM Person p $left Person f ON p.friend = f", "FROM person p $left person f ON p.friend = f.uuid"];
            case 3:
                // a join not by equality: every pair is tried
                $this->aliases[] = 'c';
                $left = $this->chance(0.5) ? 'LEFT JOIN' : 'JOIN';
                return ["FROM Person p $left Company c ON c.size < p.age", "FROM person p $left company c ON c.size < p.age"];
            default:
                return ['FROM Person p', 'FROM person p'];
        }
    }

    private function aggregate(): array
    {
        return match ($this->random(8)) {
            0 => ['COUNT(*)', 'COUNT(*)'],
            1 => (function (): array {
                [$a, $as] = $this->pick([$this->number(), $this->string()]);
                return ["COUNT($a)", "COUNT($as)"];
            })(),
            2 => (function (): array {
                [$a, $as] = $this->string();
                return ["COUNT(DISTINCT $a)", "COUNT(DISTINCT $as)"];
            })(),
            3 => (function (): array {
                [$a, $as] = $this->number();
                return ["SUM($a)", "SUM($as)"];
            })(),
            4 => (function (): array {
                [$a, $as] = $this->number();
                return ["AVG($a)", "AVG($as)"];
            })(),
            5 => (function (): array {
                [$a, $as] = $this->number();
                return ["SUM(DISTINCT $a)", "SUM(DISTINCT $as)"];
            })(),
            default => (function (): array {
                [$a, $as] = $this->pick([$this->number(), $this->string()]);
                $f = $this->pick(['MIN', 'MAX']);
                return ["$f($a)", "$f($as)"];
            })(),
        };
    }

    /**
     * @return array{0: string, 1: string, 2: bool} OQL, SQL and whether the order of the rows is defined
     */
    private function query(): array
    {
        [$from, $fromSql] = $this->from();
        $where = $whereSql = '';
        if ($this->chance(0.7)) {
            [$w, $ws] = $this->predicate();
            [$where, $whereSql] = [" WHERE $w", " WHERE $ws"];
        }

        $grouped = $this->chance(0.45);
        $select = [];
        $groupBy = [];
        if ($grouped) {
            $keys = $this->random(3); // 0: aggregates only
            for ($i = 0; $i < $keys; $i++) {
                $key = $this->pick([$this->stringColumn(), $this->numberColumn(), $this->booleanColumn(), $this->string(1)]);
                $groupBy[] = $key;
                $select[] = $key;
            }
            for ($i = 0, $n = 1 + $this->random(3); $i < $n; $i++) {
                $select[] = $this->aggregate();
            }
        } else {
            for ($i = 0, $n = 1 + $this->random(3); $i < $n; $i++) {
                $select[] = $this->pick([$this->number(), $this->string(), $this->booleanColumn()]);
            }
        }

        $distinct = !$grouped && $this->chance(0.25) ? 'DISTINCT ' : '';
        $items = $itemsSql = [];
        foreach ($select as $i => [$o, $s]) {
            $items[] = "$o AS v$i";
            $itemsSql[] = "$s AS v$i";
        }
        $oql = "SELECT $distinct" . implode(', ', $items) . " $from$where";
        $sql = "SELECT $distinct" . implode(', ', $itemsSql) . " $fromSql$whereSql";

        if ($grouped && [] !== $groupBy) {
            $oql .= ' GROUP BY ' . implode(', ', array_column($groupBy, 0));
            $sql .= ' GROUP BY ' . implode(', ', array_column($groupBy, 1));
        }
        if ($grouped && $this->chance(0.35)) {
            [$a, $as] = $this->aggregate();
            [$n] = $this->numberLiteral();
            $having = $this->pick([["$a > $n", "$as > $n"], ["$a IS NOT NULL", "$as IS NOT NULL"], ["COUNT(*) >= 2", 'COUNT(*) >= 2']]);
            $oql .= " HAVING $having[0]";
            $sql .= " HAVING $having[1]";
        }

        // ordered by all columns, each way: the order of the rows is defined (equal rows are alike)
        $ordered = $this->chance(0.6);
        if ($ordered) {
            $order = [];
            foreach (array_keys($select) as $i) {
                $order[] = ($i + 1) . ($this->chance(0.5) ? ' DESC' : '');
            }
            shuffle($order);
            // by alias or by position
            $orderOql = array_map(fn(string $o): string => $this->chance(0.5) ? 'v' . ((int)$o - 1) . substr($o, strlen((string)(int)$o)) : $o, $order);
            $oql .= ' ORDER BY ' . implode(', ', $orderOql);
            $sql .= ' ORDER BY ' . implode(', ', $order);
            if ($this->chance(0.4)) {
                $limit = $this->random(15);
                $offset = $this->random(6);
                $oql .= " LIMIT $limit OFFSET $offset";
                $sql .= " LIMIT $limit OFFSET $offset";
            }
        }
        return [$oql, $sql, $ordered];
    }

    // ---- random writes: [oql, sql] ----

    private function uuidsOf(string $class): array
    {
        return array_column($this->oql->query("SELECT UUID FROM $class ORDER BY UUID")->rows, 0);
    }

    /** a value for a property of a person, of its type: [oql, sql] */
    private function personValue(string $property): array
    {
        if ($this->chance(0.1)) {
            return ['NULL', 'NULL'];
        }
        switch ($property) {
            case 'age':
                $k = $this->random(20);
                return $this->pick([[(string)$k, (string)$k], ["p.age + $k", "p.age + $k"], ["p.age - $k", "p.age - $k"], ["p.age * 2", 'p.age * 2'],
                    ['ABS(p.age)', 'ABS(p.age)'], ["COALESCE(p.age, $k)", "COALESCE(p.age, $k)"], ['LENGTH(p.name)', 'LENGTH(p.name)'],
                    ['p.company.size', '(SELECT size FROM company WHERE uuid = p.company)']]);
            case 'score':
                [$n] = $this->numberLiteral();
                return $this->pick([[$n, $n], ["p.score + $n", "p.score + $n"], ['p.score * 1.5', 'p.score * 1.5'], ['p.score / 4', 'CAST(p.score AS REAL) / 4'],
                    ['p.age + p.score', 'p.age + p.score'], ['p.company.size', '(SELECT size FROM company WHERE uuid = p.company)']]);
            case 'active':
                return $this->pick([['TRUE', 'TRUE'], ['FALSE', 'FALSE'], ['NOT p.active', 'NOT p.active'], ['p.age > 30', 'p.age > 30'],
                    ['p.age > p.company.size', 'p.age > (SELECT size FROM company WHERE uuid = p.company)']]);
            case 'name':
            case 'city':
                [$s] = $this->stringLiteral();
                return $this->pick([[$s, $s], ["LOWER(p.$property)", "LOWER(p.$property)"], ["p.$property || 'x'", "p.$property || 'x'"],
                    ["COALESCE(p.$property, $s)", "COALESCE(p.$property, $s)"], ['p.company.name', '(SELECT name FROM company WHERE uuid = p.company)']]);
            case 'company':
                $uuid = $this->pick($this->uuidsOf('Company') ?: [null]);
                return null === $uuid ? ['NULL', 'NULL'] : ["REF('$uuid')", "'$uuid'"];
            default: // friend
                $uuid = $this->pick($this->uuidsOf('Person') ?: [null]);
                return null === $uuid ? ['NULL', 'NULL'] : $this->pick([["REF('$uuid')", "'$uuid'"]]);
        }
    }

    /**
     * @return array{0: string, 1: ?string, 2: string} OQL, SQL (null for INSERT: written with the UUIDs OQL gives)
     *         and the statement
     */
    private function write(): array
    {
        $this->aliases = ['p'];
        switch ($this->random(10)) {
            case 0:
            case 1:
                // a person, with values but no paths
                $values = [];
                foreach (['name', 'age', 'score', 'active', 'city'] as $property) {
                    $values[$property] = match ($property) {
                        'age' => (string)$this->random(80),
                        'score' => $this->numberLiteral()[0],
                        'active' => $this->pick(['TRUE', 'FALSE', 'NULL']),
                        default => $this->chance(0.2) ? 'NULL' : $this->stringLiteral()[0],
                    };
                }
                return ['INSERT INTO Person (' . implode(', ', array_keys($values)) . ') VALUES (' . implode(', ', $values) . ')', null, 'INSERT'];
            case 2:
                [$w, $ws] = $this->predicate();
                return ["DELETE FROM Person p WHERE $w", "DELETE FROM person AS p WHERE $ws", 'DELETE'];
            case 3:
                $size = $this->random(60);
                return ["DELETE FROM Company WHERE size < $size", "DELETE FROM company WHERE size < $size", 'DELETE'];
            default:
                $set = $setSql = [];
                foreach ($this->some(['name', 'age', 'score', 'active', 'city', 'company', 'friend'], 1 + $this->random(3)) as $property) {
                    [$o, $s] = $this->personValue($property);
                    $set[] = "$property = $o";
                    $setSql[] = "$property = $s";
                }
                [$w, $ws] = $this->chance(0.85) ? $this->predicate() : ['TRUE', 'TRUE'];
                return ['UPDATE Person p SET ' . implode(', ', $set) . " WHERE $w", 'UPDATE person AS p SET ' . implode(', ', $setSql) . " WHERE $ws", 'UPDATE'];
        }
    }

    private function some(array $list, int $count): array
    {
        shuffle($list);
        return array_slice($list, 0, $count);
    }

    /** the tables of both, as SQL rows */
    private function tables(): array
    {
        $people = 'SELECT UUID, name, age, score, active, city, company, friend FROM Person ORDER BY UUID';
        $companies = 'SELECT UUID, name, country, size FROM Company ORDER BY UUID';
        return [
            [self::rows($this->oql->query($people)->rows, true), self::rows($this->oql->query($companies)->rows, true)],
            [self::rows($this->sql->query(strtolower(str_replace('UUID', 'uuid', $people)))->fetchAll(PDO::FETCH_NUM), true),
                self::rows($this->sql->query(strtolower(str_replace('UUID', 'uuid', $companies)))->fetchAll(PDO::FETCH_NUM), true)],
        ];
    }

    #[DataProvider('seeds')]
    public function testWritesGiveTheResultsOfSql(int $seed): void
    {
        $this->seed($seed + 1000);
        $this->writing = true;
        $count = (int)(getenv('OQL_WRITES') ?: 60);
        $statements = ['INSERT' => 0, 'UPDATE' => 0, 'DELETE' => 0];
        $affected = 0;
        for ($i = 0; $i < $count; $i++) {
            [$oql, $sql, $statement] = $this->write();
            try {
                $result = $this->oql->query($oql);
            } catch (Throwable $e) {
                $this->fail("#$i $oql\n  error: " . $e->getMessage());
            }
            if (null === $sql) {
                // the INSERT with the UUID OQL gave the object
                $values = substr($oql, strpos($oql, 'VALUES (') + 8, -1);
                $values = str_replace(['TRUE', 'FALSE'], ['1', '0'], $values);
                $sql = sprintf("INSERT INTO person (uuid, name, age, score, active, city) VALUES ('%s', %s)", $result->column(0)[0], $values);
                $changed = $this->sql->exec($sql);
            } else {
                $changed = $this->sql->exec($sql);
            }
            // UPDATE counts the objects that changed, SQL the rows that matched: the tables tell
            if ('UPDATE' !== $statement) {
                $this->assertSame($changed, $result->affected(), "#$i $oql: affected");
            }
            [[$people, $companies], [$peopleSql, $companiesSql]] = $this->tables();
            $differences = static fn(array $expected, array $actual): string => json_encode([
                'expected' => array_values(array_udiff($expected, $actual, static fn($a, $b) => strcmp(json_encode($a), json_encode($b)))),
                'actual' => array_values(array_udiff($actual, $expected, static fn($a, $b) => strcmp(json_encode($a), json_encode($b)))),
            ]);
            $this->assertTrue(self::same($peopleSql, $people), "#$i $oql\n  SQL: $sql\n  people differ: " . $differences($peopleSql, $people));
            $this->assertTrue(self::same($companiesSql, $companies), "#$i $oql\n  companies differ: " . $differences($companiesSql, $companies));
            $statements[$statement]++;
            $affected += $result->affected();
        }
        $this->assertGreaterThan(0, min($statements), 'every statement was tried');
        $this->assertGreaterThan($count, $affected, 'the statements changed objects');
    }

    // ---- comparing ----

    /** a value to compare: numbers as floats (booleans are 1 and 0), strings marked as strings */
    private static function normalize(mixed $value): mixed
    {
        if (is_bool($value)) {
            $value = (int)$value;
        }
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }
        return null === $value ? null : 's:' . $value;
    }

    private static function rows(array $rows, bool $ordered): array
    {
        $rows = array_map(static fn(array $row): array => array_map([self::class, 'normalize'], array_values($row)), $rows);
        if (!$ordered) {
            // a canonical order, by values rounded far below the tolerance of comparing
            $key = static fn(array $row): string => json_encode(array_map(static fn($v) => is_float($v) ? sprintf('%.6e', $v) : $v, $row));
            usort($rows, static fn(array $a, array $b): int => strcmp($key($a), $key($b)));
        }
        return $rows;
    }

    /** the same rows: numbers equal up to a relative 1e-9, as sums of floats depend on their order */
    private static function same(array $expected, array $actual): bool
    {
        if (count($expected) !== count($actual)) {
            return false;
        }
        foreach ($expected as $r => $row) {
            if (count($row) !== count($actual[$r])) {
                return false;
            }
            foreach ($row as $c => $value) {
                $other = $actual[$r][$c];
                if (is_float($value) && is_float($other)) {
                    if (abs($value - $other) > 1e-9 * max(1.0, abs($value), abs($other))) {
                        return false;
                    }
                } elseif ($value !== $other) {
                    return false;
                }
            }
        }
        return true;
    }

    /** more for a single run: OQL_SEEDS=20 OQL_QUERIES=1000 vendor/bin/phpunit --filter OqlSqliteTest */
    public static function seeds(): array
    {
        $seeds = [];
        for ($seed = 1, $count = (int)(getenv('OQL_SEEDS') ?: 3); $seed <= $count; $seed++) {
            $seeds["seed $seed"] = [$seed];
        }
        return $seeds;
    }

    #[DataProvider('seeds')]
    public function testQueriesGiveTheResultsOfSql(int $seed): void
    {
        $this->seed($seed);
        $failures = [];
        $empty = 0;
        $count = (int)(getenv('OQL_QUERIES') ?: 250);
        for ($i = 0; $i < $count; $i++) {
            [$oql, $sql, $ordered] = $this->query();
            try {
                $expected = self::rows($this->sql->query($sql)->fetchAll(PDO::FETCH_NUM), $ordered);
            } catch (Throwable $e) {
                $this->fail("SQL failed: $sql\n" . $e->getMessage());
            }
            try {
                $actual = self::rows($this->oql->query($oql)->rows, $ordered);
            } catch (Throwable $e) {
                $failures[] = "#$i $oql\n  error: " . $e->getMessage();
                continue;
            }
            if (!self::same($expected, $actual)) {
                $failures[] = sprintf("#%d %s\n  SQL: %s\n  expected: %s\n  actual:   %s", $i, $oql, $sql,
                    json_encode(array_slice($expected, 0, 6)), json_encode(array_slice($actual, 0, 6)));
            }
            $empty += [] === $expected ? 1 : 0;
        }
        $this->assertSame([], array_slice($failures, 0, 8), sprintf('%d of %d queries differ (seed %d)', count($failures), $count, $seed));
        $this->assertLessThan($count / 2, $empty, 'the queries find something');
    }
}
