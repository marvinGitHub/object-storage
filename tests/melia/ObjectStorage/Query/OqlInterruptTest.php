<?php

namespace Tests\melia\ObjectStorage\Query;

use melia\ObjectStorage\Query\Exception\QueryInterruptedException;
use melia\ObjectStorage\Query\Exception\ScriptException;
use melia\ObjectStorage\Query\Oql;
use stdClass;
use Tests\melia\ObjectStorage\TestCase;

/**
 * Stopping a running statement with the $interrupt of Oql: after a maximum runtime or when cancelled; what it wrote is
 * rolled back.
 */
class OqlInterruptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        for ($i = 0; $i < 20; $i++) {
            $item = new stdClass();
            $item->n = $i;
            $this->storage->store($item);
        }
    }

    /** an interrupt that stops after it was called $calls times */
    private static function after(int $calls): \Closure
    {
        $count = 0;
        return static function () use (&$count, $calls): void {
            if (++$count > $calls) {
                throw new QueryInterruptedException('cancelled');
            }
        };
    }

    public function testWithoutInterruptionTheQueryRuns(): void
    {
        $oql = new Oql($this->storage, interrupt: static function (): void {
        });
        $this->assertSame(20, $oql->query('SELECT COUNT(*) FROM stdClass')->rows[0][0]);
    }

    public function testASelectIsStopped(): void
    {
        $oql = new Oql($this->storage, interrupt: self::after(5));
        $this->expectException(QueryInterruptedException::class);
        $oql->query('SELECT n FROM stdClass');
    }

    public function testAJoinIsStoppedWhilePairing(): void
    {
        // 20 objects read each side, then the pairs (400) are tried
        $oql = new Oql($this->storage, interrupt: self::after(100));
        $this->expectException(QueryInterruptedException::class);
        $oql->query('SELECT a.n, b.n FROM stdClass a JOIN stdClass b ON a.n < b.n');
    }

    public function testAnUpdateStoppedWhileWritingIsRolledBack(): void
    {
        // 20 objects read, then 10 written before it stops
        $oql = new Oql($this->storage, interrupt: self::after(30));
        try {
            $oql->query('UPDATE stdClass SET n = n + 100');
            $this->fail('Not stopped');
        } catch (QueryInterruptedException $e) {
            $this->assertStringContainsString('rolled back', $e->getMessage());
        }
        $this->storage->clearCache();
        $this->assertSame(0, (new Oql($this->storage))->query('SELECT COUNT(*) FROM stdClass WHERE n >= 100')->rows[0][0]);
    }

    public function testAScriptStopsAtTheStatementStopped(): void
    {
        // the first statement reads 20 objects, the DELETE 20 too, then deletes 5 before it stops
        $oql = new Oql($this->storage, interrupt: self::after(45));
        try {
            $oql->script("SELECT COUNT(*) FROM stdClass; DELETE FROM stdClass; SELECT COUNT(*) FROM stdClass");
            $this->fail('Not stopped');
        } catch (ScriptException $e) {
            $this->assertInstanceOf(QueryInterruptedException::class, $e->getPrevious());
            $this->assertSame(1, $e->statement);
            $this->assertCount(1, $e->results);
        }
        $this->storage->clearCache();
        $this->assertSame(20, (new Oql($this->storage))->query('SELECT COUNT(*) FROM stdClass')->rows[0][0], 'nothing deleted');
    }

    public function testTimeLimit(): void
    {
        $oql = new Oql($this->storage, interrupt: Oql::timeLimit(0.0001));
        usleep(1000);
        $this->expectException(QueryInterruptedException::class);
        $this->expectExceptionMessage('maximum runtime of 0.0001 s');
        $oql->query('SELECT n FROM stdClass');
    }

    public function testATimeLimitNotReachedChangesNothing(): void
    {
        $oql = new Oql($this->storage, interrupt: Oql::timeLimit(60));
        $this->assertSame(20, $oql->query('SELECT COUNT(*) FROM stdClass')->rows[0][0]);
    }
}
