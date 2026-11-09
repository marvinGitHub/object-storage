<?php

namespace Tests\melia\ObjectStorage\Query;

use DateTimeImmutable;
use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Query\Oql;
use stdClass;
use Tests\melia\ObjectStorage\TestCase;

/**
 * CONCAT, CONCAT_WS and DATE_SUB.
 */
class OqlConcatDateSubTest extends TestCase
{
    private Oql $oql;

    protected function setUp(): void
    {
        parent::setUp();
        $o = new stdClass();
        $o->first = 'Homa';
        $o->last = null;
        $o->age = 36;
        $o->price = 1.5;
        $o->active = true;
        $o->at = new DateTimeImmutable('2026-03-31T10:00:00', new \DateTimeZone('Europe/Berlin'));
        $this->storage->store($o);
        $this->oql = new Oql($this->storage);
    }

    private function value(string $expression, array $parameters = []): mixed
    {
        return $this->oql->query("SELECT $expression FROM stdClass", $parameters)->rows[0][0];
    }

    public function testConcat(): void
    {
        $this->assertSame('Homa (36)', $this->value("CONCAT(first, ' (', age, ')')"));
        $this->assertSame('Homa', $this->value('CONCAT(first, last)'), 'NULL is left out');
        $this->assertNull($this->value('first || last'), 'unlike ||');
        $this->assertSame('', $this->value('CONCAT(last)'));
        $this->assertSame('1.5 1', $this->value("CONCAT(price, ' ', active)"), 'numbers and booleans as text');
        $this->assertSame('at 2026-03-31T08:00:00.000000Z', $this->value("CONCAT('at ', at)"), 'dates in UTC');
        $this->assertSame('x:7', $this->value('CONCAT(?, ?, ?)', ['x', ':', 7]));
    }

    public function testConcatWithSeparator(): void
    {
        $this->assertSame('Homa, 36', $this->value("CONCAT_WS(', ', first, last, age)"), 'NULL and its separator left out');
        $this->assertSame('Homa', $this->value("CONCAT_WS('-', first)"));
        $this->assertSame('', $this->value("CONCAT_WS('-', last)"));
        $this->assertNull($this->value('CONCAT_WS(last, first, age)'), 'no separator');
        $this->assertSame([['Homa']], $this->oql->query("SELECT first FROM stdClass WHERE CONCAT_WS(' ', first, last) = 'Homa'")->rows);
    }

    public function testDateSub(): void
    {
        $this->assertSame('2026-03-24T09:00:00.000000Z', $this->value("DATE_SUB(at, 1, 'WEEK')"));
        $this->assertSame($this->value("DATE_ADD(at, -3, 'DAY')"), $this->value("DATE_SUB(at, 3, 'DAYS')"));
        $this->assertSame('2026-03-31T09:00:00.000000Z', $this->value("DATE_SUB(at, -1, 'HOUR')"), 'a negative amount adds');
        // in the time zone of the date: 10:00 in Berlin before the change to summer time, 09:00 UTC
        $this->assertSame('2026-03-28T09:00:00.000000Z', $this->value("DATE_SUB(at, 3, 'DAY')"));
        // a month without the day goes on: March 31 minus a month is February 31, March 3
        $this->assertSame('2026-03-03T09:00:00.000000Z', $this->value("DATE_SUB(at, 1, 'MONTH')"));
        $this->assertSame('2026-01-01T00:00:00.000000Z', $this->value("DATE_SUB('2026-01-02', 1, 'DAY')"));
        $this->assertNull($this->value("DATE_SUB(last, 1, 'DAY')"));
        $this->assertSame([[1]], $this->oql->query("SELECT COUNT(*) FROM stdClass WHERE at > DATE_SUB(NOW(), 100, 'YEAR')")->rows);
    }

    public function testDateSubErrors(): void
    {
        foreach (["DATE_SUB(at, 1, 'FORTNIGHT')" => "Unknown unit 'FORTNIGHT' for DATE_SUB", "DATE_SUB(at, 1.5, 'DAY')" => 'DATE_SUB subtracts whole days', 'DATE_SUB(at, 1)' => 'DATE_SUB takes 3 arguments'] as $expression => $message) {
            try {
                $this->value($expression);
                $this->fail($expression);
            } catch (QueryException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }
}
