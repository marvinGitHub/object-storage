<?php

namespace Tests\melia\ObjectStorage\Query;

use melia\ObjectStorage\Query\Aggregates;
use melia\ObjectStorage\Query\Ast\FunctionCall;
use melia\ObjectStorage\Query\Ast\Literal;
use PHPUnit\Framework\TestCase;

class AggregatesTest extends TestCase
{
    /**
     * The value of an aggregate of the given values, accumulated one after another.
     */
    private function aggregate(FunctionCall $call, array $values): mixed
    {
        $state = Aggregates::newState();
        foreach ($values as $value) {
            Aggregates::accumulate($call, $state, $value);
        }
        return Aggregates::value($call, [spl_object_id($call) => $state]);
    }

    private function call(string $name, bool $distinct = false, bool $star = false): FunctionCall
    {
        return new FunctionCall($name, $star ? [] : [new Literal(null)], $distinct, $star);
    }

    public function testAggregatesLeaveOutNull(): void
    {
        $values = [3, null, 1, 3];

        $this->assertSame(4, $this->aggregate($this->call('COUNT', star: true), $values), 'COUNT(*) counts every row');
        $this->assertSame(3, $this->aggregate($this->call('COUNT'), $values));
        $this->assertSame(2, $this->aggregate($this->call('COUNT', distinct: true), $values));
        $this->assertSame(7, $this->aggregate($this->call('SUM'), $values), 'an integer');
        $this->assertSame(4, $this->aggregate($this->call('SUM', distinct: true), $values));
        $this->assertSame(7 / 3, $this->aggregate($this->call('AVG'), $values));
        $this->assertSame(1, $this->aggregate($this->call('MIN'), $values));
        $this->assertSame(3, $this->aggregate($this->call('MAX'), $values));
        $this->assertSame('b', $this->aggregate($this->call('MAX'), ['a', 'b', null]), 'texts');
    }

    public function testAggregatesOfNoValues(): void
    {
        $this->assertSame(0, $this->aggregate($this->call('COUNT'), [null]));
        foreach (['SUM', 'AVG', 'MIN', 'MAX'] as $name) {
            $this->assertNull($this->aggregate($this->call($name), [null]), $name);
        }
        $this->assertSame(0, Aggregates::value($this->call('COUNT', star: true), []), 'a group without running values');
    }
}
