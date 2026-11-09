<?php

namespace Tests\melia\ObjectStorage\Query;

use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Query\Oql;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\melia\ObjectStorage\TestCase;

/**
 * The math functions and the operators %, DIV, as in MySQL.
 */
class OqlMathTest extends TestCase
{
    private Oql $oql;

    protected function setUp(): void
    {
        parent::setUp();
        $o = new stdClass();
        $o->n = null;
        $o->price = 19.99;
        $o->qty = 7;
        $o->text = '12.5';
        $this->storage->store($o);
        $this->oql = new Oql($this->storage);
    }

    private function value(string $expression): mixed
    {
        return $this->oql->query("SELECT $expression FROM stdClass")->rows[0][0];
    }

    public static function values(): array
    {
        return [
            // the operators
            ['1 + 2 * 3 - 4 / 2', 5],
            ['7 / 2', 3.5],
            ['7 % 3', 1],
            ['-7 % 3', -1],
            ['7 % -3', 1],
            ['7.5 % 2', 1.5],
            ['7 % 0', null],
            ['qty % 2', 1],
            ['7 DIV 2', 3],
            ['-7 DIV 2', -3],
            ['7.9 DIV 2', 3],
            ['7 DIV 0', null],
            ['qty DIV 2 * 2', 6],
            ['2 + 3 % 2', 3],
            // rounding
            ['CEIL(1.59)', 2],
            ['CEIL(-1.59)', -1],
            ['CEILING(2)', 2],
            ['FLOOR(1.59)', 1],
            ['FLOOR(-1.59)', -2],
            ['FLOOR(price)', 19],
            ['TRUNCATE(1.555, 2)', 1.55],
            ['TRUNCATE(0.29, 2)', 0.29],
            ['TRUNCATE(-1.999, 1)', -1.9],
            ['TRUNCATE(199.99, -2)', 100.0],
            ['TRUNCATE(-199, -1)', -190],
            ['TRUNCATE(19.99, 0)', 19.0],
            ['TRUNCATE(7, 2)', 7],
            ['ROUND(price, 1)', 20.0],
            ['MOD(11, 3)', 2],
            ['MOD(10.5, 3)', 1.5],
            ['MOD(-11, 3)', -2],
            ['MOD(11, 0)', null],
            ['SIGN(-4)', -1],
            ['SIGN(0)', 0],
            ['SIGN(price)', 1],
            ['ABS(-3)', 3],
            // powers and logarithms
            ['POW(2, 10)', 1024],
            ['POWER(2, -1)', 0.5],
            ['POW(4, 0.5)', 2.0],
            ['POW(0, -1)', null],
            ['POW(-8, 0.5)', null],
            ['SQRT(16)', 4.0],
            ['SQRT(-1)', null],
            ['EXP(0)', 1.0],
            ['LN(1)', 0.0],
            ['LN(0)', null],
            ['LOG(2, 8)', 3.0],
            ['LOG(1, 8)', null],
            ['LOG10(1000)', 3.0],
            ['LOG2(1024)', 10.0],
            ['ROUND(LOG(EXP(2)), 10)', 2.0],
            // trigonometry
            ['ROUND(PI(), 6)', 3.141593],
            ['ROUND(SIN(PI() / 2), 10)', 1.0],
            ['ROUND(COS(0), 10)', 1.0],
            ['ROUND(TAN(PI() / 4), 10)', 1.0],
            ['ROUND(COT(PI() / 4), 10)', 1.0],
            ['COT(0)', null],
            ['ROUND(ASIN(1), 6)', 1.570796],
            ['ACOS(2)', null],
            ['ROUND(ATAN(1), 6)', 0.785398],
            ['ROUND(ATAN(1, -1), 6)', 2.356194],
            ['ROUND(ATAN2(1, -1), 6)', 2.356194],
            ['DEGREES(PI())', 180.0],
            ['RADIANS(180) = PI()', true],
            // others
            ["CRC32('MySQL')", 3259397556],
            ["CONV('a', 16, 2)", '1010'],
            ["CONV(255, 10, 16)", 'FF'],
            ["CONV('-ff', 16, 10)", '-255'],
            ["CONV('9', 8, 10)", null],
            ["CONV('1', 1, 10)", null],
            // NULL and texts
            ['CEIL(n)', null],
            ['POW(2, n)', null],
            ['n % 2', null],
            ['FLOOR(text)', 12],
            ["CEIL('abc')", 0],
        ];
    }

    #[DataProvider('values')]
    public function testValue(string $expression, mixed $expected): void
    {
        $value = $this->value($expression);
        if (true === $expected) {
            $this->assertTrue($value, $expression);
            return;
        }
        $this->assertSame($expected, $value, $expression);
    }

    public function testRand(): void
    {
        $values = array_column($this->oql->query('SELECT RAND() FROM stdClass')->rows, 0);
        $this->assertGreaterThanOrEqual(0, $values[0]);
        $this->assertLessThan(1, $values[0]);
    }

    public function testDivIsNoKeyword(): void
    {
        $o = new stdClass();
        $o->div = 4;
        $o->qty = 9;
        $this->storage->store($o);
        $this->assertSame([[4, 2]], $this->oql->query('SELECT div, qty DIV div FROM stdClass WHERE div IS NOT NULL')->rows);
        $this->assertSame([['div' => 7]], $this->oql->query('SELECT qty div FROM stdClass WHERE qty = 7')->toArray(), 'an alias');
        $this->assertSame([[1]], $this->oql->query('SELECT COUNT(*) FROM stdClass WHERE qty % 2 = 1 AND qty DIV 2 = 3')->rows);
    }

    public function testArguments(): void
    {
        foreach (['SELECT POW(2) FROM stdClass' => 'POW takes 2 arguments', 'SELECT PI(1) FROM stdClass' => 'PI takes 0 arguments', 'SELECT LOG() FROM stdClass' => 'LOG takes 1 to 2 arguments'] as $query => $message) {
            try {
                $this->oql->query($query);
                $this->fail($query);
            } catch (QueryException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }
}
