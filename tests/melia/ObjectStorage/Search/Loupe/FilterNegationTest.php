<?php

namespace Tests\melia\ObjectStorage\Search\Loupe;

use Loupe\Loupe\Internal\Filter\Lexer;
use melia\ObjectStorage\Search\Exception\SearchException;
use melia\ObjectStorage\Search\Loupe\FilterNegation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FilterNegationTest extends TestCase
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
            'without NOT unchanged' => ["price >= 10 AND tags NOT IN ('a') AND city IS NOT NULL", "price >= 10 AND tags NOT IN ('a') AND city IS NOT NULL"],
            'comparisons' => ["NOT (a = 'x')", "a != 'x'"],
            'not equal' => ["NOT (a != 'x')", "a = 'x'"],
            'greater' => ['NOT (a > 1)', '(a <= 1 OR a IS NULL)'],
            'greater or equal' => ['NOT (a >= -1.5)', '(a < -1.5 OR a IS NULL)'],
            'lower' => ['NOT (a < 1)', '(a >= 1 OR a IS NULL)'],
            'lower or equal' => ['NOT (a <= 1)', '(a > 1 OR a IS NULL)'],
            'in' => ["NOT (tags IN ('a', 'b'))", "tags NOT IN ('a', 'b')"],
            'not in' => ["NOT (tags NOT IN ('a'))", "tags IN ('a')"],
            'is null' => ['NOT (city IS NULL)', 'city IS NOT NULL'],
            'is not empty' => ['NOT (tags IS NOT EMPTY)', 'tags IS EMPTY'],
            'between' => ['NOT (price BETWEEN 10 AND 20)', '(price < 10 OR price > 20 OR price IS NULL)'],
            'like' => ["NOT (name LIKE 'Ho%')", "name NOT LIKE 'Ho%'"],
            'not contains' => ["NOT (name NOT CONTAINS 'om')", "name CONTAINS 'om'"],
            'without parentheses' => ["NOT a = 'x'", "a != 'x'"],
            'De Morgan: and' => ["NOT (a = 1 AND b = 2)", 'a != 1 OR b != 2'],
            'De Morgan: or' => ["NOT (a = 1 OR b = 2)", 'a != 1 AND b != 2'],
            'double' => ['NOT (NOT (a = 1))', 'a = 1'],
            'after AND' => ["c = true AND NOT (a = 1 OR b BETWEEN 1 AND 2)", 'c = true AND (a != 1 AND (b < 1 OR b > 2 OR b IS NULL))'],
            'nested' => ["(x = 'O''Neill' OR y = 1) AND NOT ((a = 1 OR b = 2) AND NOT c IS NULL)", "(x = 'O''Neill' OR y = 1) AND ((a != 1 AND b != 2) OR c IS NULL)"],
            'paths' => ["NOT (friend.name = 'Homa')", "friend.name != 'Homa'"],
        ];
    }

    #[DataProvider('filters')]
    public function testNotIsRewritten(string $filter, string $expected): void
    {
        $this->assertSame($expected, FilterNegation::rewrite($filter));
    }

    public function testGeoFiltersCantBeNegated(): void
    {
        $this->expectException(SearchException::class);
        FilterNegation::rewrite('NOT (_geoRadius(location, 1, 2, 3))');
    }

    public function testInvalidFiltersFail(): void
    {
        $this->expectException(SearchException::class);
        FilterNegation::rewrite('NOT (a = 1');
    }
}
