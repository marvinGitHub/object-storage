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
use melia\ObjectStorage\Search\PatternIndex;
use stdClass;
use Tests\melia\ObjectStorage\TestCase;

/**
 * LIKE with pattern indexes of search indexes: the rows are always those found without indexes, also with values
 * that aren't texts (numbers, booleans, dates, lists, missing values).
 */
class OqlPatternAccessTest extends TestCase
{
    private ?SearchManager $search = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(LoupeFactory::class) || !extension_loaded('intl')) {
            $this->markTestSkipped('Loupe needs loupe/loupe and ext-intl');
        }
        $names = [
            'Homa', 'homeland', 'Johnson', 'Jackson', 'Testing', 'contest', 'A test', 'ÄPFEL', 'Äpfel und Birnen',
            "O'Pine 50%", '', 'Ho', 2.0, 25, true, false, null, ['test', 'Homa'], new DateTimeImmutable('2026-01-01T00:00:00Z'),
        ];
        foreach ($names as $i => $name) {
            $item = new stdClass();
            $item->id = $i;
            if (0 !== $i % 7) { // some don't have it at all
                $item->name = $name;
            }
            $item->code = $name;
            $this->storage->store($item);
        }
        $this->search = new SearchManager($this->storage, [new LoupeEngine()]);
        $this->search->create(new Definition('items', stdClass::class, [
            new Field('name', 'name', FieldType::STRING, filterable: true, patterns: [PatternIndex::TRIE, PatternIndex::REVERSE_TRIE, PatternIndex::NGRAM]),
            new Field('code', 'code', FieldType::STRING, filterable: true, patterns: [PatternIndex::TRIE]),
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
            ["name LIKE 'ho%'", 'search index'],
            ["name LIKE 'Ho%a'", 'search index'],
            ["name LIKE '%son'", 'search index'],
            ["name LIKE 'J%son'", 'search index'],
            ["name LIKE '%test%'", 'search index'],
            ["name LIKE '%TEST'", 'search index'],
            ["name LIKE '%con_est%'", 'search index'],
            ["name LIKE '%äpf%'", 'search index'],
            ["name LIKE 'o''pine 50\\%'", 'search index'],
            ["name LIKE 'ho'", 'search index'],
            ["name LIKE :p", 'search index', ['p' => '%tes%']],
            ["name LIKE '%nothing%'", 'search index'],
            ["name LIKE 'ho%' AND id > 0", 'search index'],
            ["code LIKE '%son'", 'all objects of the class'],
            ["code LIKE 'jack%'", 'search index'],
            // not looked up
            ["name LIKE '%'", 'all objects of the class'],
            ["name LIKE '%e%'", 'all objects of the class'],
            ["name LIKE '2%'", 'all objects of the class'],
            ["name LIKE '%.0'", 'all objects of the class'],
            ["name LIKE '%te%'", 'all objects of the class'],
            ["name LIKE '%te_t%'", 'all objects of the class'], // no 3 characters in a row
            ["name NOT LIKE 'ho%'", 'all objects of the class'],
            ["name LIKE 'ho%' OR id = 1", 'all objects of the class'],
            ["name LIKE '%rue'", 'search index'],
            ["name LIKE '2026%'", 'all objects of the class'],
            ["name LIKE '%T00:00%'", 'search index'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('queries')]
    public function testTheSameRowsWithAndWithoutIndexes(string $condition, string $access, array $parameters = []): void
    {
        $query = "SELECT id FROM stdClass WHERE $condition";
        $with = new Oql($this->storage, $this->search);
        $without = new Oql($this->storage, $this->search, useIndexes: false);
        $this->assertSame($this->rows($without, $query, $parameters), $this->rows($with, $query, $parameters), $query);
        $explained = $with->query("EXPLAIN $query", $parameters)->rows[0];
        $this->assertSame($access, $explained[2], "$query: {$explained[3]}");
    }

    public function testTheFilterIsShown(): void
    {
        $explained = (new Oql($this->storage, $this->search))->query("EXPLAIN SELECT id FROM stdClass WHERE name LIKE '%test%'")->rows[0][3];
        $this->assertStringContainsString("(name LIKE '%test%' OR name IS NULL)", $explained);
    }
}
