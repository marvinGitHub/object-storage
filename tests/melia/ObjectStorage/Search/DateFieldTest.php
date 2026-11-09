<?php

namespace Tests\melia\ObjectStorage\Search;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\Search\DocumentBuilder;
use melia\ObjectStorage\Search\Exception\InvalidIndexDefinitionException;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\Index;
use melia\ObjectStorage\Search\Index\Manager;
use melia\ObjectStorage\Search\Loupe\FilterDates;
use melia\ObjectStorage\Search\Loupe\LoupeEngine;
use melia\ObjectStorage\Search\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\melia\ObjectStorage\TestCase;

/**
 * Date fields: dates (stored inline, or texts that are dates) indexed as the seconds since 1970 UTC, filtered with
 * texts that are dates, sorted, returned as their point in time in UTC.
 */
class DateFieldTest extends TestCase
{
    private ?Manager $search = null;

    public function tearDown(): void
    {
        if (null !== $this->search) {
            $this->search->detach();
            foreach ($this->search->list() as $index) {
                $index->close();
            }
            $this->search = null;
            gc_collect_cycles();
        }
        parent::tearDown();
    }

    // ---- types ----

    public static function conversions(): array
    {
        $date = new DateTimeImmutable('2026-01-01 01:00:00.5', new DateTimeZone('Europe/Berlin'));
        return [
            'date' => [FieldType::DATE, $date, 1767225600.5],
            'mutable date' => [FieldType::DATE, new DateTime('1969-12-31T23:59:59Z'), -1],
            'text' => [FieldType::DATE, '2026-01-01', 1767225600],
            'text with offset' => [FieldType::DATE, '2026-01-01T01:00+01:00', 1767225600],
            'number' => [FieldType::DATE, 1767225600, 1767225600],
            'not a date' => [FieldType::DATE, 'soon', null],
            'list' => [FieldType::DATE_ARRAY, [$date, 'soon', '2026-01-02'], [1767225600.5, 1767312000]],
            'as text' => [FieldType::STRING, $date, '2026-01-01T00:00:00.500000Z'],
            'as number' => [FieldType::NUMBER, $date, 1767225600.5],
        ];
    }

    #[DataProvider('conversions')]
    public function testConversion(FieldType $type, mixed $value, mixed $expected): void
    {
        $this->assertSame($expected, $type->convert($value));
    }

    public function testGuessAndLists(): void
    {
        $this->assertSame(FieldType::DATE, FieldType::guess(new DateTimeImmutable()));
        $this->assertSame(FieldType::DATE_ARRAY, FieldType::guess([new DateTimeImmutable(), new DateTime()]));
        $this->assertTrue(FieldType::DATE_ARRAY->isList());
        $this->assertTrue(FieldType::DATE->isNumeric());

        $this->expectException(InvalidIndexDefinitionException::class);
        new Field('visits', 'visits', FieldType::DATE_ARRAY, sortable: true);
    }

    // ---- documents ----

    public function testDocumentsHaveDatesAlsoInListsAndReferencedObjects(): void
    {
        $customer = new stdClass();
        $customer->since = new DateTimeImmutable('2020-05-01T00:00:00Z');
        $order = new stdClass();
        $order->placedAt = new DateTimeImmutable('2026-01-01T10:00:00+01:00');
        $order->lines = [['at' => new DateTimeImmutable('2026-01-02T00:00:00Z')], ['at' => new DateTimeImmutable('2026-01-03T00:00:00Z')]];
        $order->customer = $customer;
        $order->legacy = '2025-12-24';
        $uuid = $this->storage->store($order);

        $definition = new Definition('orders', stdClass::class, [
            new Field('placedAt', 'placedAt', FieldType::DATE, filterable: true, sortable: true),
            new Field('lineDates', 'lines.at', FieldType::DATE_ARRAY, filterable: true),
            new Field('customerSince', 'customer.since', FieldType::DATE, filterable: true),
            new Field('legacy', 'legacy', FieldType::DATE, filterable: true),
            new Field('placedAtText', 'placedAt', FieldType::STRING, filterable: true),
        ]);
        $reader = function (string $uuid): ?array {
            $raw = $this->storage->readRaw($uuid);
            return [json_decode($raw->data, true), $raw->metadata->getReservedReferenceName(), $raw->metadata->getClassName()];
        };
        $raw = $this->storage->readRaw($uuid);
        $document = (new DocumentBuilder($definition, $reader))->build($uuid, json_decode($raw->data, true), $raw->metadata);

        $this->assertSame(1767258000, $document['placedAt']);
        $this->assertSame([1767312000, 1767398400], $document['lineDates'], 'a date is one value, not its parts');
        $this->assertSame(1588291200, $document['customerSince']);
        $this->assertSame(1766534400, $document['legacy'], 'texts that are dates');
        $this->assertSame('2026-01-01T09:00:00.000000Z', $document['placedAtText']);
    }

    public function testDatesAreSuggestedAsDateFields(): void
    {
        $order = new stdClass();
        $order->placedAt = new DateTimeImmutable('2026-01-01T10:00:00Z');
        $order->visits = [new DateTimeImmutable('2026-01-02T00:00:00Z')];
        $uuid = $this->storage->store($order);

        $fields = [];
        foreach (DocumentBuilder::suggestFields(json_decode($this->storage->readRaw($uuid)->data, true), Metadata::RESERVED_REFERENCE_NAME_DEFAULT) as $field) {
            $fields[$field->path] = [$field->type, $field->filterable, $field->sortable, $field->searchable];
        }

        $this->assertSame([
            'placedAt' => [FieldType::DATE, true, true, false],
            'visits' => [FieldType::DATE_ARRAY, true, false, false],
        ], $fields, 'not the parts of the dates (placedAt.__datetime, …)');
    }

    // ---- filters ----

    public static function filters(): array
    {
        return [
            'comparison' => ["at >= '2026-01-01'", "at >= 1767225600"],
            'offset and fraction' => ["at < '2026-01-01T01:00:00.25+01:00'", "at < 1767225600.25"],
            'in and between' => ["at IN ('2026-01-01', '2026-01-02') OR at BETWEEN '2025-01-01' AND '2026-01-01'", "at IN (1767225600, 1767312000) OR at BETWEEN 1735689600 AND 1767225600"],
            'other fields stay' => ["name = '2026-01-01' AND at > '1969-12-31T23:59:59Z'", "name = '2026-01-01' AND at > -1"],
            'not a date stays' => ["at = 'soon'", "at = 'soon'"],
            'numbers stay' => ['at > 1767225600', 'at > 1767225600'],
        ];
    }

    #[DataProvider('filters')]
    public function testFiltersWriteDatesAsNumbers(string $filter, string $expected): void
    {
        $this->assertSame($expected, FilterDates::rewrite($filter, ['at']));
    }

    // ---- Loupe ----

    private function index(): Index
    {
        if (!extension_loaded('intl') || !class_exists(\Loupe\Loupe\Loupe::class)) {
            $this->markTestSkipped('Loupe needs loupe/loupe and ext-intl');
        }
        foreach ([
            'New Year' => new DateTimeImmutable('2026-01-01 00:30:00', new DateTimeZone('Europe/Berlin')), // 2025-12-31T23:30Z
            'Late UTC' => new DateTimeImmutable('2025-12-31T23:45:00Z'),
            'New York' => new DateTimeImmutable('2025-12-31 20:00:00', new DateTimeZone('America/New_York')), // 2026-01-01T01:00Z
            'Moon' => new DateTimeImmutable('1969-07-20T20:17:40Z'),
            'None' => null,
        ] as $title => $at) {
            $event = new stdClass();
            $event->title = $title;
            $event->at = $at;
            $this->storage->store($event);
        }
        $this->search = new Manager($this->storage, [new LoupeEngine()]);
        $index = $this->search->create(new Definition('events', stdClass::class, [
            new Field('title', 'title', searchable: false, filterable: true),
            new Field('at', 'at', FieldType::DATE, searchable: false, filterable: true, sortable: true),
        ]));
        $index->build();
        return $index;
    }

    /** @return string[] titles */
    private function titles(Index $index, ?string $filter, array $sort = ['at:asc']): array
    {
        $result = $index->search(new Request(filter: $filter, sort: $sort, hitsPerPage: 100));
        return array_map(static fn($hit): string => $hit->document['title'], $result->hits);
    }

    public function testLoupeFiltersAndSortsDates(): void
    {
        $index = $this->index();

        $this->assertSame(['New York'], $this->titles($index, "at >= '2026-01-01'"));
        $this->assertSame(['New Year', 'Late UTC', 'New York'], $this->titles($index, "at >= '2026-01-01T00:00+01:00'"));
        $this->assertSame(['Late UTC', 'New York'], $this->titles($index, "at BETWEEN '2025-12-31T23:40Z' AND '2026-01-01T02:00+01:00'"));
        $this->assertSame(['Moon'], $this->titles($index, "at < '1970-01-01'"), 'before 1970: negative numbers');
        $this->assertSame(['Late UTC'], $this->titles($index, "at IN ('2025-12-31 23:45')"));
        $this->assertSame(['Moon', 'Late UTC'], $this->titles($index, "NOT (at > '1970-01-01' AND at < '2025-12-31T23:40Z') AND at IS NOT NULL AND at != '2026-01-01T01:00Z'"));
        $this->assertSame(['New York', 'Late UTC', 'New Year', 'Moon'], $this->titles($index, 'at IS NOT NULL', ['at:desc']));
    }

    public function testHitsHaveDatesAsTheirPointInTime(): void
    {
        $index = $this->index();
        $hits = $index->search(new Request(filter: "title = 'New Year'"))->hits;

        $this->assertSame('2025-12-31T23:30:00.000000Z', $hits[0]->document['at']);
    }
}
