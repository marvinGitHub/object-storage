<?php

namespace Tests\melia\ObjectStorage\Query;

use melia\ObjectStorage\Query\Values;
use PHPUnit\Framework\TestCase;

class ValuesTest extends TestCase
{
    public function testThreeValuedLogic(): void
    {
        $values = [true, false, null];
        // the truth tables of SQL
        $and = [[true, false, null], [false, false, false], [null, false, null]];
        $or = [[true, true, true], [true, false, null], [true, null, null]];
        foreach ($values as $i => $a) {
            foreach ($values as $j => $b) {
                $this->assertSame($and[$i][$j], Values::and($a, $b), 'AND');
                $this->assertSame($or[$i][$j], Values::or($a, $b), 'OR');
            }
        }
        $this->assertNull(Values::not(null));
        $this->assertFalse(Values::not(true));
    }

    public function testComparing(): void
    {
        $this->assertSame(0, Values::compare(1, 1.0));
        $this->assertSame(0, Values::compare(true, 1), 'booleans are 1 and 0');
        $this->assertSame(-1, Values::compare(2, 10));
        $this->assertSame(1, Values::compare('b', 'B'), 'strings by their bytes');
        $this->assertSame(-1, Values::compare(10, '9x'), 'numbers before strings');
        $this->assertSame(1, Values::compare(10, '9'), 'but a number as text is a number');
        $this->assertNull(Values::compare(null, 1));
        $this->assertNull(Values::compare(null, null));
        $this->assertNull(Values::compare(['a'], ['a']), 'lists can\'t be compared');
        // a number and a number as text: as numbers (objects have no schema), as in MySQL
        $this->assertSame(-1, Values::compare('97.65', 199));
        $this->assertSame(0, Values::compare(1, '1.0'));
        $this->assertSame(1, Values::compare(' 2 ', 1));
        $this->assertSame(1, Values::compare('2', '10'), 'two strings: as text');
        $this->assertSame(-1, Values::compare(5, 'abc'), 'not a number: after the numbers');
        $this->assertSame(Values::equalityKey('1.0'), Values::equalityKey(1));
        $this->assertNotSame(Values::key('1.0'), Values::key(1), 'grouped apart');
    }

    public function testSorting(): void
    {
        $values = ['b', 2, null, ['x'], 1.5, 'B', false];
        usort($values, [Values::class, 'sortOrder']);
        $this->assertSame([null, false, 1.5, 2, 'B', 'b', ['x']], $values);
    }

    public function testKeys(): void
    {
        $this->assertSame(Values::key(1), Values::key(1.0));
        $this->assertSame(Values::key(true), Values::key(1));
        $this->assertNotSame(Values::key(1), Values::key('1'));
        $this->assertNotSame(Values::key(null), Values::key(''));
        $this->assertNotSame(Values::key(0.1), Values::key(0.1 + 0.2 - 0.2), 'floats exactly');
    }

    public function testTruthNumbersAndText(): void
    {
        $this->assertNull(Values::truth(null));
        $this->assertFalse(Values::truth(0));
        $this->assertTrue(Values::truth(-0.5));
        $this->assertTrue(Values::truth('1abc'));
        $this->assertFalse(Values::truth('abc'));
        $this->assertSame(12, Values::number('12abc'));
        $this->assertSame(0, Values::number('abc'));
        $this->assertSame(1.5, Values::number('1.5'));
        $this->assertSame('2.0', Values::text(2.0));
        $this->assertSame('1', Values::text(true));
    }

    public function testLike(): void
    {
        $this->assertTrue(Values::like('Homa', 'h_MA'));
        $this->assertTrue(Values::like('Ärger', 'ä%'));
        $this->assertTrue(Values::like('50%', '50\\%'));
        $this->assertFalse(Values::like('500', '50\\%'));
        $this->assertTrue(Values::like("a\nb", 'a%b'), 'across lines');
        $this->assertTrue(Values::like('a.b', 'a.b'));
        $this->assertFalse(Values::like('axb', 'a.b'), 'no regular expression');
        $this->assertTrue(Values::like('', '%'));
    }
}
