<?php

namespace Tests\melia\ObjectStorage\Query;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use melia\ObjectStorage\Query\Exception\QueryException;
use Loupe\Loupe\LoupeFactory;
use melia\ObjectStorage\Query\Oql;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\Manager as SearchManager;
use melia\ObjectStorage\Search\Loupe\LoupeEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\melia\ObjectStorage\TestCase;

/**
 * Dates in OQL: compared by their point in time, also with texts that are dates; sorted, grouped, the date
 * functions, and dates written by INSERT and UPDATE.
 */
class OqlDateTest extends TestCase
{
    private Oql $oql;

    /** @var array<string, string> title => UUID */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $berlin = new DateTimeZone('Europe/Berlin');
        $this->events['newYear'] = $this->storage->store(new Event('New Year', new DateTimeImmutable('2026-01-01 00:30:00', $berlin))); // 2025-12-31T23:30Z
        $this->events['lateUtc'] = $this->storage->store(new Event('Late UTC', new DateTimeImmutable('2025-12-31 23:45:00', new DateTimeZone('UTC'))));
        $this->events['newYork'] = $this->storage->store(new Event('New York', new DateTimeImmutable('2025-12-31 20:00:00', new DateTimeZone('America/New_York')))); // 2026-01-01T01:00Z
        $this->events['summer'] = $this->storage->store(new Event('Summer', new DateTimeImmutable('2026-07-15 12:00:00.250000', $berlin)));
        $this->events['none'] = $this->storage->store(new Event('No date', null));
        $this->oql = new Oql($this->storage);
    }

    private function rows(string $query, array $parameters = []): array
    {
        return $this->oql->query($query, $parameters)->rows;
    }

    private function column(string $query, array $parameters = []): array
    {
        return array_column($this->rows($query, $parameters), 0);
    }

    private function value(string $query, array $parameters = []): mixed
    {
        return $this->rows($query, $parameters)[0][0] ?? null;
    }

    public function testDatesAreReturnedAsTheirPointInTimeInUtc(): void
    {
        $this->assertSame('2025-12-31T23:30:00.000000Z', $this->value("SELECT at FROM Event WHERE title = 'New Year'"));
        $result = $this->oql->query("SELECT * FROM Event WHERE title = 'Summer'");
        $this->assertSame('2026-07-15T10:00:00.250000Z', $result->rows[0][array_search('at', $result->columns, true)]);
    }

    public function testDatesSortByTheirPointInTimeAcrossTimeZones(): void
    {
        $this->assertSame(['No date', 'New Year', 'Late UTC', 'New York', 'Summer'], $this->column('SELECT title FROM Event ORDER BY at'));
        $this->assertSame(['Summer', 'New York', 'Late UTC', 'New Year', 'No date'], $this->column('SELECT title FROM Event ORDER BY at DESC'));
    }

    public static function ranges(): array
    {
        return [
            'date only, UTC' => ["at >= '2026-01-01'", ['New York', 'Summer']],
            'with offset' => ["at >= '2026-01-01T00:00:00+01:00'", ['New Year', 'Late UTC', 'New York', 'Summer']],
            'with Z' => ["at < '2025-12-31T23:45Z'", ['New Year']],
            'space and minutes' => ["at = '2025-12-31 23:45'", ['Late UTC']],
            'same point, other zone' => ["at = '2026-01-01T00:30:00+01:00'", ['New Year']],
            'between' => ["at BETWEEN '2025-12-31T23:40:00Z' AND '2026-01-01T02:00:00+01:00'", ['Late UTC', 'New York']],
            'in' => ["at IN ('2026-07-15T12:00:00.25+02:00', '2025-12-31T20:00:00-05:00')", ['New York', 'Summer']],
            // a text that isn't a date is compared with the date as text, as in SQLite
            'not a date' => ["at = 'tomorrow'", []],
            'date that does not exist' => ["at > '2026-02-30'", ['Summer']],
            'null' => ['at IS NULL', ['No date']],
        ];
    }

    #[DataProvider('ranges')]
    public function testDatesAreComparedWithTexts(string $condition, array $expected): void
    {
        $this->assertSame($expected, $this->column("SELECT title FROM Event WHERE $condition ORDER BY at"));
    }

    public function testDatesAreComparedWithDateParameters(): void
    {
        $from = new DateTime('2026-01-01 00:00:00', new DateTimeZone('UTC'));
        $this->assertSame(['New York', 'Summer'], $this->column('SELECT title FROM Event WHERE at >= :from ORDER BY at', ['from' => $from]));
        $this->assertSame(['New York', 'Summer'], $this->column('SELECT title FROM Event WHERE at >= ? ORDER BY at', ['2026-01-01']));
    }

    public function testMinMaxAndGroupingOfDates(): void
    {
        $this->assertSame([['2025-12-31T23:30:00.000000Z', '2026-07-15T10:00:00.250000Z']], $this->rows('SELECT MIN(at), MAX(at) FROM Event'));
        // the year in UTC and in Berlin
        $this->assertSame([[null, 1], [2025, 2], [2026, 2]], $this->rows('SELECT YEAR(at) AS y, COUNT(*) FROM Event GROUP BY y ORDER BY y'));
        $this->assertSame([[null, 1], [2026, 4]], $this->rows("SELECT YEAR(at, 'Europe/Berlin') AS y, COUNT(*) FROM Event GROUP BY y ORDER BY y"));
        $this->assertSame(['2025-12-31', '2025-12-31', '2026-01-01', '2026-07-15'], $this->column('SELECT DATE(at) FROM Event WHERE at IS NOT NULL ORDER BY at'));
    }

    public function testPartsOfDates(): void
    {
        $this->assertSame([[2026, 7, 15, 10, 0, 0]], $this->rows("SELECT YEAR(at), MONTH(at), DAY(at), HOUR(at), MINUTE(at), SECOND(at) FROM Event WHERE title = 'Summer'"));
        $this->assertSame([12], $this->column("SELECT HOUR(at, 'Europe/Berlin') FROM Event WHERE title = 'Summer'"));
        $this->assertSame([6], $this->column("SELECT HOUR(at, '+05:00') FROM Event WHERE title = 'New York'"));
        $this->assertSame([2026], $this->column("SELECT YEAR('2026-03-01') FROM Event LIMIT 1"), 'texts that are dates too');
        $this->assertSame([null], $this->column("SELECT YEAR('no date') FROM Event LIMIT 1"));
    }

    public function testDatetimeConvertsTextsAndNumbers(): void
    {
        $this->assertSame('2026-01-01T09:00:00.000000Z', $this->value("SELECT DATETIME('2026-01-01 10:00', 'Europe/Berlin') FROM Event LIMIT 1"));
        $this->assertSame('2026-01-01T10:00:00.000000Z', $this->value("SELECT DATETIME('2026-01-01 10:00') FROM Event LIMIT 1"));
        $this->assertSame('2026-01-01T10:00:00.000000Z', $this->value("SELECT DATETIME('2026-01-01T10:00Z', 'Europe/Berlin') FROM Event LIMIT 1"), 'an offset in the text wins');
        $this->assertSame('1970-01-02T00:00:00.500000Z', $this->value('SELECT DATETIME(86400.5) FROM Event LIMIT 1'));
        $this->assertNull($this->value("SELECT DATETIME('2026-02-30') FROM Event LIMIT 1"));
    }

    public function testNowAndDateAdd(): void
    {
        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $now = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u\Z', $this->value('SELECT NOW() FROM Event LIMIT 1'), new DateTimeZone('UTC'));
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $now->getTimestamp());
        $this->assertLessThan($before->getTimestamp() + 60, $now->getTimestamp());
        $this->assertSame([true, true, true, true, true], $this->column('SELECT NOW() = NOW() FROM Event'), 'the same in a statement');
        $this->assertSame(['Summer'], $this->column("SELECT title FROM Event WHERE at > DATE_ADD(NOW(), -5, 'YEAR') AND at > '2026-02-01'"));

        $this->assertSame(['Summer'], $this->column("SELECT title FROM Event WHERE at >= DATE_ADD('2026-07-15T12:00:00Z', -1, 'DAY')"));
        $this->assertSame('2026-03-03T00:00:00.000000Z', $this->value("SELECT DATE_ADD('2026-01-31', 1, 'MONTH') FROM Event LIMIT 1"));
        $this->assertSame('2025-12-01T00:00:00.000000Z', $this->value("SELECT DATE_ADD('2026-01-01', -31, 'days') FROM Event LIMIT 1"));
        $this->assertSame('2026-01-01T00:01:30.000000Z', $this->value("SELECT DATE_ADD('2026-01-01', 90, 'second') FROM Event LIMIT 1"));
        // a day of the calendar of the date, across daylight saving time: 23 hours in Berlin
        $this->storage->store(new Event('Spring', new DateTimeImmutable('2026-03-28 12:00:00', new DateTimeZone('Europe/Berlin'))));
        $this->assertSame('2026-03-29T10:00:00.000000Z', $this->value("SELECT DATE_ADD(at, 1, 'DAY') FROM Event WHERE title = 'Spring'"));
        $this->assertSame(1.0, (float)$this->value("SELECT DATE_ADD('2026-01-01', 1, 'SECOND') - DATETIME('2026-01-01') FROM Event LIMIT 1"), 'dates are seconds in arithmetic');
    }

    public function testErrorsOfDateFunctions(): void
    {
        foreach ([
            "SELECT YEAR(at, 'Mars/Olympus') FROM Event" => "Unknown time zone 'Mars/Olympus'",
            "SELECT DATE_ADD(at, 1, 'fortnight') FROM Event" => "Unknown unit 'fortnight'",
            "SELECT DATE_ADD(at, 1.5, 'DAY') FROM Event" => 'whole days',
            'SELECT NOW(1) FROM Event' => 'NOW',
        ] as $query => $message) {
            try {
                $this->oql->query($query);
                $this->fail("$query: no error");
            } catch (QueryException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $query);
            }
        }
    }

    public function testDatesInReferencedObjects(): void
    {
        $holder = new stdClass();
        $holder->event = $this->storage->load($this->events['summer']);
        $this->storage->store($holder);

        $this->assertSame(['2026-07-15T10:00:00.250000Z'], $this->column("SELECT h.event.at FROM stdClass h WHERE h.event.at > '2026-06-01'"));
    }

    public function testInsertAndUpdateWriteDates(): void
    {
        $uuid = $this->oql->query("INSERT INTO Event (title, at) VALUES ('Launch', '2026-11-09T10:00:00+01:00')")->column(0)[0];

        $this->storage->clearCache();
        $event = $this->storage->load($uuid);
        $this->assertInstanceOf(DateTimeImmutable::class, $event->at);
        $this->assertSame('2026-11-09T10:00:00+01:00', $event->at->format(DATE_ATOM), 'the offset of the text is its time zone');
        $this->assertSame('+01:00', $event->at->getTimezone()->getName());

        $this->oql->query("UPDATE Event SET at = DATE_ADD(at, 7, 'DAY') WHERE title = 'Summer'");
        $this->storage->clearCache();
        $summer = $this->storage->load($this->events['summer']);
        $this->assertSame('2026-07-22 12:00:00.250000 Europe/Berlin', $summer->at->format('Y-m-d H:i:s.u e'), 'the time zone is kept');

        $this->oql->query('UPDATE Event SET at = NULL WHERE title = :t', ['t' => 'Summer']);
        $this->assertNull($this->value("SELECT at FROM Event WHERE title = 'Summer'"));
    }

    public function testWrittenDatesTakeTheDeclaredClass(): void
    {
        $uuid = $this->oql->query('INSERT INTO `' . MutableEvent::class . "` (at, any) VALUES ('2026-01-01', '2026-01-02')")->column(0)[0];
        $this->storage->clearCache();
        $event = $this->storage->load($uuid);
        $this->assertInstanceOf(DateTime::class, $event->at);
        $this->assertInstanceOf(DateTimeImmutable::class, $event->any);

        // a date for a string, and a text that isn't a date for a date, are refused
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('not the text');
        $this->oql->query("UPDATE Event SET at = 'next week'");
    }

    public function testDateForTextPropertyIsRefused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('is ?string, not the date');
        $this->oql->query("UPDATE Event SET title = DATETIME('2026-01-01')");
    }

    public function testUntypedPropertiesTakeDatesAndKeepTexts(): void
    {
        $holder = new stdClass();
        $holder->name = 'holder';
        $uuid = $this->storage->store($holder);
        $this->oql->query("UPDATE stdClass SET at = DATETIME('2026-01-01'), text = '2026-01-01' WHERE name = 'holder'");

        $this->storage->clearCache();
        $loaded = $this->storage->load($uuid);
        $this->assertInstanceOf(DateTimeImmutable::class, $loaded->at);
        $this->assertSame('2026-01-01', $loaded->text);
    }

    public function testSearchIndexIsNotUsedForTextsThatAreDates(): void
    {
        if (!class_exists(LoupeFactory::class) || !extension_loaded('intl')) {
            $this->markTestSkipped('Loupe needs loupe/loupe and ext-intl');
        }
        // a field of text over dates has no values for them: the index can't find dates by a text
        $search = new SearchManager($this->storage, [new LoupeEngine()]);
        $search->create(new Definition('events', Event::class, [
            new Field('title', 'title', searchable: false, filterable: true),
            new Field('at', 'at', searchable: false, filterable: true),
        ]))->build();
        $oql = new Oql($this->storage, $search);

        $query = "SELECT title FROM Event WHERE at = '2025-12-31T23:45:00Z'";
        $this->assertSame(['Late UTC'], array_column($oql->query($query)->rows, 0));
        $this->assertSame('all objects of the class', $oql->query('EXPLAIN ' . $query)->rows[0][2]);
        $this->assertNotSame('all objects of the class', $oql->query("EXPLAIN SELECT at FROM Event WHERE title = 'Summer'")->rows[0][2], 'other texts still use it');
    }

    public function testDateParametersCanBeGroupedBy(): void
    {
        $date = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $this->assertSame([['2026-01-02T00:00:00.000000Z', 5]], $this->rows("SELECT DATE_ADD(?, 1, 'DAY'), COUNT(*) FROM Event GROUP BY DATE_ADD(?, 1, 'DAY')", [$date, $date]));
    }
}

class Event
{
    public function __construct(public ?string $title = null, public ?DateTimeImmutable $at = null)
    {
    }
}

class MutableEvent
{
    public ?DateTime $at = null;
    public ?DateTimeInterface $any = null;
}
