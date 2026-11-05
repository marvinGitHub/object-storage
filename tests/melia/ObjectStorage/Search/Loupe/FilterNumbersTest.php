<?php

namespace Tests\melia\ObjectStorage\Search\Loupe;

use Loupe\Loupe\Internal\Filter\Lexer;
use melia\ObjectStorage\Search\Loupe\FilterNumbers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FilterNumbersTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Lexer::class)) {
            $this->markTestSkipped('Loupe needs loupe/loupe');
        }
    }

    public static function filters(): array
    {
        return [
            'nothing to do' => ["price > 1 AND name = 'a-b'", "price > 1 AND name = 'a-b'"],
            'a negative number' => ['price > -7.5', "price > '-7.5'"],
            'spaces' => ['price >= - 1', "price >= '-1'"],
            'lists' => ['price IN (-1, 2, -3)', "price IN ('-1', 2, '-3')"],
            'not in' => ['price NOT IN (-1)', "price NOT IN ('-1')"],
            'between' => ['price BETWEEN 1 AND 5', '(price >= 1 AND price <= 5)'],
            'between negative' => ['price BETWEEN -8 AND -1.5', "(price >= '-8' AND price <= '-1.5')"],
            'between in the wrong order' => ['price BETWEEN 5 AND 1', '(price >= 5 AND price <= 1)'],
            'not between' => ['price NOT BETWEEN -1 AND 1', "(price < '-1' OR price > 1 OR price IS NULL)"],
            'combined' => ["(a = -1 OR b BETWEEN -2 AND 2) AND c != 'x'", "(a = '-1' OR (b >= '-2' AND b <= 2)) AND c != 'x'"],
            'strings stay' => ["name = '-1' AND x = -1", "name = '-1' AND x = '-1'"],
            'quotes in strings' => ["name = 'O''-1' AND x < -1", "name = 'O''-1' AND x < '-1'"],
            'geo filters stay' => ['_geoRadius(location, -45.5, -12, 1000) AND x > -1', "_geoRadius(location, -45.5, -12, 1000) AND x > '-1'"],
            'geo bounding box' => ['_geoBoundingBox(location, 1, -2, -3, 4)', '_geoBoundingBox(location, 1, -2, -3, 4)'],
        ];
    }

    #[DataProvider('filters')]
    public function testNumbersAreWrittenAsLoupeTakesThem(string $filter, string $expected): void
    {
        $this->assertSame($expected, FilterNumbers::rewrite($filter));
    }
}
