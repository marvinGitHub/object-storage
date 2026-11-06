<?php

namespace Tests\melia\ObjectStorage\Query;

use Loupe\Loupe\LoupeFactory;
use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Query\Oql;
use melia\ObjectStorage\Query\Result;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\Manager as SearchManager;
use melia\ObjectStorage\Search\Loupe\LoupeEngine;
use melia\ObjectStorage\Unique\Building;
use melia\ObjectStorage\Unique\Definition as UniqueDefinition;
use melia\ObjectStorage\Unique\Manager as UniqueManager;
use stdClass;
use Tests\melia\ObjectStorage\TestCase;

/**
 * How the objects of a query are found: by their UUID, a unique index or a search index, only where the index names
 * every object the condition is true for; the rows are always those found without indexes. Also the limit of rows
 * (maxRows) and that sorting with LIMIT gives the rows of a full sort.
 */
class OqlAccessTest extends TestCase
{
    /** @var array<string, string> name => UUID */
    private array $users = [];
    private ?SearchManager $search = null;

    protected function setUp(): void
    {
        parent::setUp();
        $cities = ['Berlin', 'Hamburg', 'Köln', '12', null];
        for ($i = 0; $i < 60; $i++) {
            $user = new stdClass();
            $user->name = sprintf('user%03d', $i);
            $user->email = sprintf('user%03d@example.com', $i);
            $user->city = $cities[$i % 5];
            $user->age = 20 + $i % 7;
            $user->code = 0 === $i % 10 ? (string)$i : 'c' . $i; // texts that are numbers
            $this->users[$user->name] = $this->storage->store($user);
        }
        $this->storage->store(new Wrapper('user001')); // the same name in another class isn't found
    }

    public function tearDown(): void
    {
        $this->search?->detach();
        $this->search = null;
        // PHPUnit keeps the test objects until the end: the cached objects (some large) aren't kept with them
        $this->storage->clearCache();
        parent::tearDown();
    }

    private function loupe(): SearchManager
    {
        if (!class_exists(LoupeFactory::class) || !extension_loaded('intl')) {
            $this->markTestSkipped('Loupe needs loupe/loupe and ext-intl');
        }
        $this->search = new SearchManager($this->storage, [new LoupeEngine()]);
        $this->search->create(new Definition('users', stdClass::class, [
            new Field('name', 'name', searchable: false, filterable: true),
            new Field('city', 'city', searchable: false, filterable: true),
            new Field('code', 'code', searchable: false, filterable: true),
            new Field('age', 'age', FieldType::NUMBER, searchable: false, filterable: true),
        ]))->build();
        return $this->search;
    }

    private function unique(): UniqueManager
    {
        $unique = new UniqueManager($this->storage);
        $unique->create(new UniqueDefinition('user-email', stdClass::class, 'email'));
        return $unique;
    }

    /** the access of the source of a SELECT, by EXPLAIN */
    private function access(Oql $oql, string $query, array $parameters = []): string
    {
        $explained = $oql->query('EXPLAIN ' . $query, $parameters);
        $this->assertSame(['source', 'class', 'access', 'details'], $explained->columns);
        return $explained->rows[0][2];
    }

    private static function sorted(Result $result): array
    {
        $rows = $result->rows;
        sort($rows);
        return $rows;
    }

    /** the rows with indexes are those without; returns the access used */
    private function assertSameRows(Oql $oql, string $query, array $parameters = []): string
    {
        $without = new Oql($this->storage, useIndexes: false);
        $this->assertSame(self::sorted($without->query($query, $parameters)), self::sorted($oql->query($query, $parameters)), $query);
        $this->assertSame('all objects of the class', $this->access($without, $query, $parameters));
        return $this->access($oql, $query, $parameters);
    }

    public function testByUuid(): void
    {
        $oql = new Oql($this->storage);
        $uuid = $this->users['user007'];
        $this->assertSame([['user007']], $oql->query('SELECT name FROM stdClass WHERE UUID = ?', [$uuid])->rows);
        $this->assertSame('UUID', $this->assertSameRows($oql, 'SELECT name FROM stdClass WHERE UUID = ?', [$uuid]));
        $this->assertSame('UUID', $this->assertSameRows($oql, 'SELECT name FROM stdClass WHERE UUID IN (?, ?) AND age > 0', [$uuid, $this->users['user008']]));
        $this->assertSame('UUID', $this->assertSameRows($oql, "SELECT name FROM stdClass WHERE UUID = 'no-uuid'"));

        // an object of another class isn't a row
        $wrapper = $oql->query('SELECT UUID FROM Wrapper')->rows[0][0];
        $this->assertSame([], $oql->query('SELECT name FROM stdClass WHERE UUID = ?', [$wrapper])->rows);

        // OR: every object
        $this->assertSame('all objects of the class', $this->assertSameRows($oql, 'SELECT name FROM stdClass WHERE UUID = ? OR age = 20', [$uuid]));
    }

    public function testByUniqueIndex(): void
    {
        $oql = new Oql($this->storage, unique: $this->unique());
        $this->assertSame([['user012']], $oql->query("SELECT name FROM stdClass WHERE email = 'user012@example.com'")->rows);
        foreach ([
                     "SELECT name FROM stdClass WHERE email = 'user012@example.com'",
                     "SELECT name FROM stdClass WHERE 'user012@example.com' = email",
                     "SELECT name FROM stdClass u WHERE u.email IN ('user012@example.com', 'user013@example.com', 'nobody') AND u.age >= 0",
                     "SELECT name FROM stdClass WHERE email = 'nobody@example.com'",
                     "SELECT name FROM stdClass WHERE email = 'user012@example.com' AND age = 99",
                 ] as $query) {
            $this->assertSame('unique index', $this->assertSameRows($oql, $query), $query);
        }
        foreach ([
                     "SELECT name FROM stdClass WHERE email LIKE 'user01%'",
                     "SELECT name FROM stdClass WHERE email != 'user012@example.com'",
                     "SELECT name FROM stdClass WHERE NOT email = 'user012@example.com'",
                     "SELECT name FROM stdClass WHERE email = 'user012@example.com' OR age = 21",
                     "SELECT name FROM stdClass WHERE email NOT IN ('user012@example.com')",
                 ] as $query) {
            $this->assertSame('all objects of the class', $this->assertSameRows($oql, $query), $query);
        }
    }

    public function testUniqueIndexBeingBuiltIsNotUsed(): void
    {
        $unique = $this->unique();
        $this->assertTrue($unique->isComplete('user-email'));
        $this->assertFalse($unique->isComplete('nothing'));

        // as if create() or rebuild() were claiming the keys (or had been interrupted): the marker is left
        $uuid = (new \ReflectionMethod(UniqueManager::class, 'buildingUuid'))->invoke(null, 'user-email');
        $unique->getInternalStorage()->store(new Building('user-email', time()), $uuid);
        $this->assertFalse($unique->isComplete('user-email'));
        $oql = new Oql($this->storage, unique: $unique);
        $this->assertSame('all objects of the class', $this->assertSameRows($oql, "SELECT name FROM stdClass WHERE email = 'user012@example.com'"));

        $unique->rebuild('user-email');
        $this->assertTrue($unique->isComplete('user-email'));
        $this->assertSame('unique index', $this->access($oql, "SELECT name FROM stdClass WHERE email = 'user012@example.com'"));

        $unique->drop('user-email');
        $this->assertFalse($unique->getInternalStorage()->exists($uuid));
    }

    public function testBySearchIndex(): void
    {
        $oql = new Oql($this->storage, $this->loupe());
        $this->assertSame([['user042']], $oql->query("SELECT name FROM stdClass WHERE name = 'user042'")->rows);
        foreach ([
                     "SELECT name FROM stdClass WHERE name = 'user042'",
                     "SELECT name FROM stdClass WHERE name = ?",
                     "SELECT name FROM stdClass WHERE name IN ('user001', 'user002', 'nobody')",
                     "SELECT name FROM stdClass WHERE city = 'Köln'",
                     "SELECT name FROM stdClass WHERE city = 'Köln' AND name IN ('user002', 'user003')",
                     "SELECT name FROM stdClass WHERE city = 'Berlin' AND age > 22",
                     "SELECT name FROM stdClass WHERE name = 'it''s'",
                     "SELECT COUNT(*), city FROM stdClass WHERE city IN ('Berlin', 'Hamburg') GROUP BY city",
                 ] as $query) {
            $this->assertSame('search index', $this->assertSameRows($oql, $query, ['user005']), $query);
        }
        foreach ([
                     // texts that are numbers equal numbers in OQL
                     "SELECT name FROM stdClass WHERE city = '12'",
                     "SELECT name FROM stdClass WHERE code = '10'",
                     "SELECT name FROM stdClass WHERE code IN ('c11', '20')",
                     // not looked up
                     "SELECT name FROM stdClass WHERE age = 21",
                     "SELECT name FROM stdClass WHERE city LIKE 'B%'",
                     "SELECT name FROM stdClass WHERE city = 'Berlin' OR city = 'Köln'",
                     "SELECT name FROM stdClass WHERE city != 'Berlin'",
                     "SELECT name FROM stdClass WHERE city IS NULL",
                     "SELECT name FROM stdClass WHERE email = 'user001@example.com'",
                 ] as $query) {
            $this->assertSame('all objects of the class', $this->assertSameRows($oql, $query), $query);
        }
    }

    public function testSearchIndexWithPendingChangesIsNotUsed(): void
    {
        $search = $this->loupe();
        $search->attach(autoSync: false);
        $oql = new Oql($this->storage, $search);
        $this->assertSame('search index', $this->access($oql, "SELECT name FROM stdClass WHERE name = 'user200'"));

        $user = new stdClass();
        $user->name = 'user200';
        $this->storage->store($user);

        $this->assertSame('all objects of the class', $this->access($oql, "SELECT name FROM stdClass WHERE name = 'user200'"));
        $this->assertSame([['user200']], $oql->query("SELECT name FROM stdClass WHERE name = 'user200'")->rows);

        $search->sync();
        $this->assertSame('search index', $this->assertSameRows($oql, "SELECT name FROM stdClass WHERE name = 'user200'"));
    }

    public function testSearchIndexNamingTooManyObjectsIsNotUsed(): void
    {
        $oql = new Oql($this->storage, $this->loupe());
        $names = array_map(static fn(int $i): string => sprintf("'user%03d'", $i), range(0, 59));
        $this->assertSame('search index', $this->assertSameRows($oql, 'SELECT name FROM stdClass WHERE name IN (' . implode(', ', $names) . ')'));
        // the limit is high (999); every object is still found below it
        $this->assertCount(60, $oql->query('SELECT name FROM stdClass WHERE name IN (' . implode(', ', $names) . ')')->rows);
    }

    public function testExplain(): void
    {
        $oql = new Oql($this->storage, unique: $this->unique());
        $explained = $oql->query("  explain SELECT u.name, f.name FROM stdClass u LEFT JOIN stdClass f ON f.name = u.city WHERE u.email = 'user001@example.com'");
        $this->assertSame('u', $explained->rows[0][0]);
        $this->assertSame('stdClass', $explained->rows[0][1]);
        $this->assertSame('unique index', $explained->rows[0][2]);
        $this->assertStringContainsString('user-email', $explained->rows[0][3]);
        $this->assertStringContainsString('1 object(s)', $explained->rows[0][3]);
        $this->assertSame('f', $explained->rows[1][0]);
        $this->assertFalse(Oql::writes('EXPLAIN SELECT name FROM stdClass'));

        try {
            $oql->query("EXPLAIN DELETE FROM stdClass");
            $this->fail('EXPLAIN of a write');
        } catch (QueryException $e) {
            $this->assertStringContainsString('EXPLAIN', $e->getMessage());
        }
        $this->assertSame(60, (new Oql($this->storage))->query('SELECT COUNT(*) FROM stdClass')->rows[0][0], 'EXPLAIN wrote nothing');
    }

    public function testMaxRows(): void
    {
        $oql = new Oql($this->storage);
        $result = $oql->query('SELECT name FROM stdClass ORDER BY name', maxRows: 10);
        $this->assertCount(10, $result->rows);
        $this->assertTrue($result->truncated);
        $this->assertSame('user009', $result->rows[9][0]);

        $result = $oql->query('SELECT name FROM stdClass ORDER BY name LIMIT 5', maxRows: 10);
        $this->assertCount(5, $result->rows);
        $this->assertFalse($result->truncated);

        $result = $oql->query('SELECT name FROM stdClass ORDER BY name LIMIT 10', maxRows: 10);
        $this->assertFalse($result->truncated, 'exactly as many as allowed');

        $result = $oql->query('SELECT city, COUNT(*) FROM stdClass GROUP BY city', maxRows: 2);
        $this->assertCount(2, $result->rows);
        $this->assertTrue($result->truncated);

        $this->assertFalse($oql->query('SELECT name FROM stdClass', maxRows: 60)->truncated);
        $this->assertTrue($oql->query('SELECT name FROM stdClass', maxRows: 59)->truncated);
    }

    /** sorting with LIMIT keeps only the first rows: the same as sorting all and taking them, also with ties */
    public function testOrderByWithLimitIsAFullSort(): void
    {
        $oql = new Oql($this->storage);
        $all = $oql->query('SELECT age, city, name FROM stdClass ORDER BY age DESC, city, name')->rows;
        foreach ([[1, 0], [5, 0], [7, 3], [13, 20], [60, 0], [100, 55], [3, 70]] as [$limit, $offset]) {
            $rows = $oql->query(sprintf('SELECT age, city, name FROM stdClass ORDER BY age DESC, city, name LIMIT %d OFFSET %d', $limit, $offset))->rows;
            $this->assertSame(array_slice($all, $offset, $limit), $rows, "LIMIT $limit OFFSET $offset");
        }
        // ties only: which rows isn't defined, but as many and all with the first values
        $rows = $oql->query('SELECT age FROM stdClass ORDER BY age LIMIT 12')->rows;
        $this->assertSame(array_fill(0, 9, [20]), array_slice($rows, 0, 9));
        $this->assertSame([21], $rows[9]);

        $distinct = $oql->query('SELECT DISTINCT city FROM stdClass ORDER BY city DESC LIMIT 2 OFFSET 1')->rows;
        $this->assertSame([['Hamburg'], ['Berlin']], $distinct);
    }

    /** reading many large objects keeps only what the query needs in memory */
    public function testMemoryStaysBounded(): void
    {
        $bio = str_repeat('Lorem ipsum dolor sit amet. ', 1000); // ~28 KB per object
        for ($i = 0; $i < 600; $i++) {
            $user = new stdClass();
            $user->name = sprintf('big%04d', $i);
            $user->team = $i % 3;
            $user->bio = $bio . $i;
            $this->storage->store($user);
        }
        $this->storage->clearCache();
        $oql = new Oql($this->storage);
        // ~17 MB of objects; the queries below need a few rows each
        foreach ([
                     'SELECT COUNT(*) FROM stdClass' => [[660]],
                     "SELECT name FROM stdClass WHERE name = 'big0599'" => [['big0599']],
                     "SELECT name FROM stdClass WHERE name LIKE 'big%' ORDER BY name DESC LIMIT 2" => [['big0599'], ['big0598']],
                     'SELECT team, COUNT(*) FROM stdClass WHERE team IS NOT NULL GROUP BY team ORDER BY team' => [[0, 200], [1, 200], [2, 200]],
                     'SELECT name FROM stdClass LIMIT 1' => null,
                 ] as $query => $expected) {
            gc_collect_cycles();
            $before = memory_get_usage();
            memory_reset_peak_usage();
            $rows = $oql->query($query)->rows;
            $used = memory_get_peak_usage() - $before;
            if (null !== $expected) {
                $this->assertSame($expected, $rows, $query);
            }
            $this->assertLessThan(6 * 1024 * 1024, $used, $query);
        }
    }
}

/** an object of another class */
final class Wrapper
{
    public function __construct(public string $name)
    {
    }
}
