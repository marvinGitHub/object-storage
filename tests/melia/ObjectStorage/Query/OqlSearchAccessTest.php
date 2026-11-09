<?php

namespace Tests\melia\ObjectStorage\Query;

use DateTimeImmutable;
use Loupe\Loupe\LoupeFactory;
use melia\ObjectStorage\Query\Oql;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\Manager as SearchManager;
use melia\ObjectStorage\Search\Loupe\LoupeEngine;
use stdClass;
use Tests\melia\ObjectStorage\TestCase;

/**
 * Search indexes for comparisons of numbers, dates and booleans: the rows are always those found without indexes,
 * also with values of other types than the field (texts in a number field, numbers as text, missing values).
 */
class OqlSearchAccessTest extends TestCase
{
    private ?SearchManager $search = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(LoupeFactory::class) || !extension_loaded('intl')) {
            $this->markTestSkipped('Loupe needs loupe/loupe and ext-intl');
        }
        // regular values, and some of other types
        $prices = [1.5, 2, 10, 10.0, 25, 49.99, -3, '12', ' 7 ', 'cheap', true, false, null, 0, 1e-3, 1000000];
        $dates = ['2026-01-01T00:00:00Z', '2026-01-15T12:30:00+01:00', '2025-12-31T23:59:59.5Z', '1969-07-20T20:17:00Z'];
        $actives = [true, false, 1, 0, '1', 'true', null, 2, 1.0];
        for ($i = 0; $i < 64; $i++) {
            $item = new stdClass();
            $item->name = sprintf('item%02d', $i);
            if (0 !== $i % 9) { // some don't have it at all
                $item->price = $prices[$i % count($prices)];
            }
            $item->createdAt = match ($i % 6) {
                0, 1, 2, 3 => new DateTimeImmutable($dates[$i % 4]),
                4 => '2026-01-10',         // a date as text
                default => $i % 2 ? 'someday' : null, // a text that isn't a date, nothing
            };
            $item->active = $actives[$i % count($actives)];
            $this->storage->store($item);
        }
        $this->search = new SearchManager($this->storage, [new LoupeEngine()]);
        $this->search->create(new Definition('items', stdClass::class, [
            new Field('price', 'price', FieldType::NUMBER, searchable: false, filterable: true),
            new Field('createdAt', 'createdAt', FieldType::DATE, searchable: false, filterable: true),
            new Field('active', 'active', FieldType::BOOLEAN, searchable: false, filterable: true),
        ]))->build();
    }

    public function tearDown(): void
    {
        $this->search?->detach();
        $this->search = null;
        parent::tearDown();
    }

    private function rows(Oql $oql, string $query, array $parameters): array
    {
        $rows = $oql->query($query, $parameters)->rows;
        sort($rows);
        return $rows;
    }

    public static function queries(): array
    {
        return [
            ['price = 10', 'search index'],
            ['price = 12', 'search index'],
            ['10 = price', 'search index'],
            ['price IN (2, 25, 7)', 'search index'],
            ['price < 10', 'search index'],
            ['price <= 10', 'search index'],
            ['price > 10', 'search index'],
            ['price >= 1.5', 'search index'],
            ['20 < price', 'search index'],
            ['price > -5', 'search index'],
            ['price < -1', 'search index'],
            ['price BETWEEN 2 AND 30', 'search index'],
            ['price BETWEEN -10 AND 0.001', 'search index'],
            ['price > :p', 'search index', ['p' => 5]],
            ['price > 0.001 AND price < 1000', 'search index'],
            ['active = TRUE', 'search index'],
            ['active = FALSE', 'search index'],
            ['active = 1', 'search index'],
            ['active = 0', 'search index'],
            ["createdAt = '2026-01-01'", 'search index'],
            ["createdAt >= '2026-01-01'", 'search index'],
            ["createdAt > '2026-01-01'", 'search index'],
            ["createdAt < '2026-01-01T00:00:00Z'", 'search index'],
            ["createdAt <= '2026-01-15T12:30+01:00'", 'search index'],
            ["createdAt BETWEEN '2025-12-31' AND '2026-01-10'", 'search index'],
            ['createdAt >= :since', 'search index', ['since' => new DateTimeImmutable('2026-01-01 01:00', new \DateTimeZone('Europe/Berlin'))]],
            ["createdAt IN ('2026-01-01', '1969-07-20T20:17:00Z')", 'search index'],
            ["createdAt < '1970-01-01'", 'search index'],
            ['price > 10 AND active = TRUE', 'search index'],
            // not looked up
            ["price > '10'", 'all objects of the class'],
            ['price != 10', 'all objects of the class'],
            ['price > 10 OR active = TRUE', 'all objects of the class'],
            ['NOT price > 10', 'all objects of the class'],
            ['active = 2', 'all objects of the class'],
            ["createdAt > 'soon'", 'all objects of the class'],
            ['price IS NULL', 'all objects of the class'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('queries')]
    public function testTheSameRowsWithAndWithoutIndexes(string $condition, string $access, array $parameters = []): void
    {
        $query = "SELECT name FROM stdClass WHERE $condition";
        $with = new Oql($this->storage, $this->search);
        $without = new Oql($this->storage, $this->search, useIndexes: false);
        $this->assertSame($this->rows($without, $query, $parameters), $this->rows($with, $query, $parameters), $query);
        $explained = $with->query("EXPLAIN $query", $parameters)->rows[0];
        $this->assertSame($access, $explained[2], "$query: {$explained[3]}");
    }

    /**
     * The assumption (docs/query.md, "Indexes"): a date field holds dates, a number field numbers. A number in a date
     * field is less than any date in OQL, but indexed as seconds; so are dates in a number field the other way.
     */
    public function testValuesOfTheOtherKindAreNotFoundByComparisonsAgainstTheirOrder(): void
    {
        $item = new stdClass();
        $item->name = 'odd';
        $item->createdAt = 1767225600; // 2026-01-01 as seconds, a number
        $item->price = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $this->storage->store($item);
        $this->search->get('items')->build();

        $with = new Oql($this->storage, $this->search);
        $without = new Oql($this->storage, $this->search, useIndexes: false);
        foreach (["createdAt < '2020-01-01'", 'price > 2000000000'] as $condition) {
            $query = "SELECT name FROM stdClass WHERE name = 'odd' AND $condition";
            $this->assertSame([['odd']], $without->query($query)->rows, 'OQL: a number is less than a date');
            $this->assertSame([], $with->query($query)->rows, 'the index: seconds');
        }
        // in the other direction, the index names them, and OQL doesn't find them either
        $query = "SELECT name FROM stdClass WHERE name = 'odd' AND createdAt >= '2026-01-01'";
        $this->assertSame($without->query($query)->rows, $with->query($query)->rows);
    }

    public function testTheFilterIsShown(): void
    {
        $explained = (new Oql($this->storage, $this->search))->query('EXPLAIN SELECT name FROM stdClass WHERE price BETWEEN 2 AND 30 AND active = TRUE')->rows[0][3];
        $this->assertStringContainsString('((price >= 2 AND price <= 30) OR price IS NULL) AND (active = true OR active IS NULL)', $explained);
    }
}
