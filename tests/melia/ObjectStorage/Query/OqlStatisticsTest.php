<?php

namespace Tests\melia\ObjectStorage\Query;

use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Query\Exception\QueryInterruptedException;
use melia\ObjectStorage\Query\Oql;
use melia\ObjectStorage\Query\SlowQueryLog;
use melia\ObjectStorage\Query\Statistics;
use stdClass;
use Tests\melia\ObjectStorage\TestCase;

/**
 * What a statement did and cost (Statistics), the observer of Oql and the slow query log.
 */
class OqlStatisticsTest extends TestCase
{
    /** @var list<Statistics> */
    private array $observed = [];

    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        for ($i = 0; $i < 10; $i++) {
            $item = new stdClass();
            $item->n = $i;
            $this->storage->store($item);
        }
        $this->logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'slow-' . bin2hex(random_bytes(6)) . '.log';
    }

    public function tearDown(): void
    {
        @unlink($this->logFile);
        @unlink($this->logFile . '.1');
        parent::tearDown();
    }

    private function oql(?callable $observer = null, ?\Closure $interrupt = null): Oql
    {
        return new Oql($this->storage, interrupt: $interrupt, observer: $observer ?? function (Statistics $statistics): void {
            $this->observed[] = $statistics;
        });
    }

    public function testASelect(): void
    {
        $result = $this->oql()->query('SELECT n FROM stdClass s WHERE n < :max', ['max' => 3]);
        $statistics = $result->statistics;
        $this->assertNotNull($statistics);
        $this->assertSame([$statistics], $this->observed);
        $this->assertSame('SELECT', $statistics->statement);
        $this->assertSame(10, $statistics->objectsRead);
        $this->assertSame(3, $statistics->rows);
        $this->assertSame([['source' => 's', 'class' => 'stdClass', 'access' => Statistics::ALL_OBJECTS]], $statistics->access);
        $this->assertSame(['s' => 'stdClass'], $statistics->fullScans());
        $this->assertGreaterThan(0, $statistics->milliseconds);
        $this->assertNull($statistics->error);
        // the statement as written, not the values
        $this->assertSame('SELECT n FROM stdClass s WHERE n < :max', $statistics->query);
    }

    public function testFoundByUuidReadsOnlyThatObject(): void
    {
        $uuid = $this->oql()->query('SELECT UUID FROM stdClass WHERE n = 4')->rows[0][0];
        $statistics = $this->oql()->query('SELECT n FROM stdClass WHERE UUID = ?', [$uuid])->statistics;
        $this->assertSame(1, $statistics?->objectsRead);
        $this->assertSame('UUID', $statistics->access[0]['access']);
        $this->assertSame([], $statistics->fullScans());
    }

    public function testAJoinCountsBothSources(): void
    {
        $statistics = $this->oql()->query('SELECT a.n FROM stdClass a JOIN stdClass b ON b.n = a.n')->statistics;
        $this->assertSame(20, $statistics?->objectsRead);
        // the joined objects are read first
        $this->assertEqualsCanonicalizing(['a', 'b'], array_column($statistics->access, 'source'));
        $this->assertSame(10, $statistics->rows);
    }

    public function testWritesAndTransactions(): void
    {
        $results = $this->oql()->script("START TRANSACTION; UPDATE stdClass SET n = n + 1 WHERE n >= 5; COMMIT")->results;
        $this->assertSame(['START TRANSACTION', 'UPDATE', 'COMMIT'], array_map(static fn(Statistics $s): ?string => $s->statement, $this->observed));
        $this->assertSame(5, $results[1]->statistics?->rows);
        $this->assertTrue($results[1]->statistics->inTransaction);
        // the statement alone, not the script
        $this->assertSame('UPDATE stdClass SET n = n + 1 WHERE n >= 5', $results[1]->statistics->query);
    }

    public function testAFailedStatementIsObserved(): void
    {
        try {
            $this->oql()->query('SELECT nope FROM stdClass GROUP BY n');
            $this->fail('No error');
        } catch (QueryException) {
        }
        $this->assertCount(1, $this->observed);
        $this->assertSame('SELECT', $this->observed[0]->statement);
        $this->assertNotNull($this->observed[0]->error);
        $this->assertSame(0, $this->observed[0]->rows);

        try {
            $this->oql()->query('SELEKT 1');
        } catch (QueryException) {
        }
        $this->assertNull($this->observed[1]->statement, 'not read');
    }

    public function testAnInterruptedStatementIsMarked(): void
    {
        $this->expectException(QueryInterruptedException::class);
        try {
            $this->oql(interrupt: static fn() => throw new QueryInterruptedException('cancelled'))->query('SELECT n FROM stdClass');
        } finally {
            $this->assertTrue($this->observed[0]->interrupted);
            $this->assertSame('cancelled', $this->observed[0]->error);
        }
    }

    public function testAFailingObserverDoesNotFailTheStatement(): void
    {
        $oql = $this->oql(static fn() => throw new \RuntimeException('broken'));
        $this->assertSame(10, $oql->query('SELECT COUNT(*) FROM stdClass')->rows[0][0]);
    }

    public function testPattern(): void
    {
        $pattern = static fn(string $query): string => (new Statistics($query, 'SELECT', 0, 0, 0))->pattern();
        $this->assertSame(
            "SELECT p.name, LOWER(p.city) FROM Product p WHERE p.price > ? AND p.name IN (?) AND tags[?] = :tag ORDER BY p.name LIMIT ?",
            $pattern("select p.name, lower(p.city) -- the city\nFROM Product p WHERE p.price > 10.5 AND p.name IN ('a', 'b', 'c') AND tags[0] = :tag ORDER BY p.name LIMIT 20")
        );
        $this->assertSame($pattern("SELECT * FROM A WHERE x = 'a'"), $pattern("SELECT *   FROM A  WHERE x = 'something else'"));
        $this->assertSame('SELECT `App\Some Class`.x FROM `App\Some Class`', $pattern('SELECT `App\Some Class`.x FROM `App\Some Class`'));
        $this->assertSame("SELECT 'open", $pattern("SELECT   'open"), 'not readable: as written');
        $this->assertSame('INSERT INTO Product (name, price) VALUES (?)', $pattern("INSERT INTO Product (name, price) VALUES ('x', 1)"));
    }

    public function testTheSlowQueryLog(): void
    {
        $log = new SlowQueryLog($this->logFile, milliseconds: 60_000, objectsRead: 10, context: ['storage' => 'test']);
        $uuid = (new Oql($this->storage))->query('SELECT UUID FROM stdClass WHERE n = 2')->rows[0][0];
        $oql = $this->oql($log);
        $oql->query('SELECT n FROM stdClass WHERE n = 1');
        $oql->query('SELECT n FROM stdClass WHERE n = 2');
        // fast and few objects: not logged
        $oql->query('SELECT n FROM stdClass WHERE UUID = ?', [$uuid]);
        // excluded
        $oql->query('SELECT /* no-slow-log */ n FROM stdClass');

        $entries = SlowQueryLog::read($this->logFile);
        $this->assertCount(2, $entries);
        $this->assertSame('test', $entries[0]['storage']);
        $this->assertSame('SELECT', $entries[0]['statement']);
        $this->assertSame(10, $entries[0]['objectsRead']);
        $this->assertSame(1, $entries[0]['rows']);
        $this->assertSame(['stdClass' => 'stdClass'], $entries[0]['fullScans']);
        $this->assertSame('SELECT n FROM stdClass WHERE n = ?', $entries[0]['pattern']);
        $this->assertSame('SELECT n FROM stdClass WHERE n = 1', $entries[0]['query']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/', $entries[0]['time']);
        $this->assertSame($entries[0]['pattern'], $entries[1]['pattern']);
    }

    public function testTheSlowQueryLogByTimeAndInterruptions(): void
    {
        $log = new SlowQueryLog($this->logFile, milliseconds: 0);
        $statistics = new Statistics('SELECT 1', 'SELECT', 0.5, 0, 1);
        $this->assertTrue($log->isSlow($statistics));
        $this->assertFalse((new SlowQueryLog($this->logFile, milliseconds: 1))->isSlow($statistics));
        $this->assertTrue((new SlowQueryLog($this->logFile, milliseconds: 1))->isSlow(new Statistics('SELECT 1', 'SELECT', 0.5, 0, 0, interrupted: true)));
    }

    public function testTheSlowQueryLogIsRotated(): void
    {
        $log = new SlowQueryLog($this->logFile, milliseconds: 0, maxBytes: 300);
        for ($i = 0; $i < 5; $i++) {
            $log(new Statistics("SELECT $i FROM stdClass", 'SELECT', 1, 0, 0));
        }
        // the older entries went with the rotation
        $this->assertFileExists($this->logFile . '.1');
        $entries = SlowQueryLog::read($this->logFile);
        $this->assertLessThan(5, count($entries));
        $this->assertSame('SELECT 4 FROM stdClass', $entries[count($entries) - 1]['query'], 'the newest last');
    }
}
