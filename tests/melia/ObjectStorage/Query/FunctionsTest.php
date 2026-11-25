<?php

namespace Tests\melia\ObjectStorage\Query;

use melia\ObjectStorage\Query\DateValue;
use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Query\Functions;
use PHPUnit\Framework\TestCase;

class FunctionsTest extends TestCase
{
    public function testNowIsTheSameForTheStatement(): void
    {
        $functions = new Functions();
        $now = $functions->call('NOW', []);

        $this->assertInstanceOf(DateValue::class, $now);
        $this->assertSame($now, $functions->call('NOW', []), 'the same for one statement');
        $this->assertNotSame($now, (new Functions())->call('NOW', []), 'another statement has its own');
    }

    public function testTexts(): void
    {
        $functions = new Functions();

        $this->assertSame('ab', $functions->call('CONCAT', ['a', null, 'b']), 'NULL is left out');
        $this->assertSame('a-1', $functions->call('CONCAT_WS', ['-', 'a', null, 1]));
        $this->assertNull($functions->call('CONCAT_WS', [null, 'a']));
        $this->assertSame('ÄPFEL', $functions->call('UPPER', ['äpfel']));
        $this->assertSame('pfe', $functions->call('SUBSTR', ['äpfel', 2, 3]));
        $this->assertSame('el', $functions->call('SUBSTR', ['äpfel', -2]));
        $this->assertSame('b', $functions->call('COALESCE', [null, 'b', 'c']));
        $this->assertNull($functions->call('LOWER', [null]), 'NULL for a first argument NULL');
        $this->assertNull($functions->call('CARDINALITY', ['text']), 'not a list');
        $this->assertSame(2, $functions->call('CARDINALITY', [[1, 2]]));
    }

    public function testDates(): void
    {
        $functions = new Functions();

        $this->assertSame('2024-03-02', $functions->call('DATE', [$functions->call('DATE_ADD', ['2024-01-31T12:00:00Z', 1, 'MONTH'])]), 'into the next month');
        $this->assertSame(23, $functions->call('HOUR', ['2024-06-01T23:30:00Z']));
        $this->assertSame(1, $functions->call('HOUR', ['2024-06-01T23:30:00Z', 'Europe/Berlin']), 'in a time zone');
        $this->assertSame(1970, $functions->call('YEAR', [0]), 'seconds since 1970');
        $this->assertNull($functions->call('DATETIME', [[1, 2]]));
    }

    public function testInvalidArgumentsFail(): void
    {
        $functions = new Functions();

        foreach ([
            ['DATE_ADD', ['2024-01-01', 1, 'FORTNIGHT'], "Unknown unit 'FORTNIGHT' for DATE_ADD"],
            ['DATE_SUB', ['2024-01-01', 1.5, 'DAY'], 'DATE_SUB subtracts whole days, not 1.5'],
            ['YEAR', ['2024-01-01', 'Mars/Olympus'], "Unknown time zone 'Mars/Olympus'"],
            ['NOPE', [1], 'Unknown function NOPE'],
        ] as [$name, $arguments, $message]) {
            try {
                $functions->call($name, $arguments);
                $this->fail('expected a QueryException for ' . $name);
            } catch (QueryException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }
}
