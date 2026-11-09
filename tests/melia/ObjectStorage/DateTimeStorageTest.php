<?php

namespace Tests\melia\ObjectStorage;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use melia\ObjectStorage\Exception\DateTimeRestorationFailureException;
use melia\ObjectStorage\LazyLoadReference;
use melia\ObjectStorage\Logger\LoggerInterface;
use melia\ObjectStorage\Serialization\DateTimeValue;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Throwable;

final class DateTimeStorageTest extends TestCase
{
    private function rawData(string $uuid): array
    {
        return json_decode(file_get_contents($this->storage->getFilePathData($uuid)), true);
    }

    private function reload(string $uuid): object
    {
        $this->storage->clearCache();
        return $this->storage->load($uuid);
    }

    public function testDateIsStoredInlineAsUtcWithTimezoneAndClass(): void
    {
        $event = new DatedEvent(new DateTimeImmutable('2026-11-09 10:00:00.123456', new DateTimeZone('Europe/Berlin')));
        $uuid = $this->storage->store($event);

        $this->assertSame([
            '__datetime' => '2026-11-09T09:00:00.123456Z',
            'tz' => 'Europe/Berlin',
            'class' => DateTimeImmutable::class,
        ], $this->rawData($uuid)['createdAt']);

        $this->assertSame([$uuid], iterator_to_array($this->storage->list(), false), 'no record of its own for the date');
    }

    public function testDateIsRestoredExactly(): void
    {
        $date = new DateTimeImmutable('2026-03-29 01:59:59.999999', new DateTimeZone('Europe/Berlin'));
        $uuid = $this->storage->store(new DatedEvent($date));

        $loaded = $this->reload($uuid);

        $this->assertInstanceOf(DateTimeImmutable::class, $loaded->createdAt);
        $this->assertEquals($date, $loaded->createdAt);
        $this->assertSame('Europe/Berlin', $loaded->createdAt->getTimezone()->getName());
        $this->assertSame($date->format('Y-m-d H:i:s.u P'), $loaded->createdAt->format('Y-m-d H:i:s.u P'));
    }

    public static function timezones(): array
    {
        return [
            'identifier' => ['America/New_York'],
            'offset' => ['+05:30'],
            'abbreviation' => ['CEST'],
            'utc' => ['UTC'],
        ];
    }

    #[DataProvider('timezones')]
    public function testEveryKindOfTimezoneIsKept(string $timezone): void
    {
        $date = new DateTimeImmutable('2026-07-01 12:34:56.5', new DateTimeZone($timezone));
        $uuid = $this->storage->store(new DatedEvent($date));

        $loaded = $this->reload($uuid)->createdAt;

        $this->assertSame($timezone, $loaded->getTimezone()->getName());
        $this->assertSame($date->format('Y-m-d H:i:s.u P'), $loaded->format('Y-m-d H:i:s.u P'));
    }

    public function testMutableDateTimeAndSubclassesKeepTheirClass(): void
    {
        $object = new stdClass();
        $object->mutable = new DateTime('2026-01-01 00:00:00', new DateTimeZone('UTC'));
        $object->custom = new CustomDate('2026-01-02 00:00:00', new DateTimeZone('UTC'));

        $loaded = $this->reload($this->storage->store($object));

        $this->assertInstanceOf(DateTime::class, $loaded->mutable);
        $this->assertInstanceOf(CustomDate::class, $loaded->custom);
        $this->assertSame('2026-01-02', $loaded->custom->format('Y-m-d'));
    }

    public function testDatesInArraysAndUntypedPropertiesAreRestored(): void
    {
        $object = new stdClass();
        $object->dates = ['start' => new DateTimeImmutable('2026-01-01T00:00:00Z'), 'nested' => [new DateTime('2026-02-01T00:00:00Z')]];

        $loaded = $this->reload($this->storage->store($object));

        $this->assertInstanceOf(DateTimeImmutable::class, $loaded->dates['start']);
        $this->assertInstanceOf(DateTime::class, $loaded->dates['nested'][0]);
        $this->assertSame('2026-02-01', $loaded->dates['nested'][0]->format('Y-m-d'));
    }

    public function testDatesReturnedByUnserializeDataAreRestored(): void
    {
        $object = new SerializedDates(new DateTimeImmutable('2026-05-05T05:05:05Z'));

        $loaded = $this->reload($this->storage->store($object));

        $this->assertInstanceOf(DateTimeImmutable::class, $loaded->at);
        $this->assertSame('2026-05-05T05:05:05+00:00', $loaded->at->format(DATE_ATOM));
    }

    public function testDateIsConvertedToTheDeclaredClass(): void
    {
        $object = new stdClass();
        $object->createdAt = new DateTime('2026-01-01T00:00:00Z');
        $uuid = $this->storage->store($object);

        // the class changed from stdClass to one declaring DateTimeImmutable
        $map = $this->storage->getClassRenameMap();
        $map->createAlias(stdClass::class, DatedEvent::class);

        $loaded = $this->reload($uuid);

        $this->assertInstanceOf(DatedEvent::class, $loaded);
        $this->assertInstanceOf(DateTimeImmutable::class, $loaded->createdAt);
        $this->assertSame('2026-01-01', $loaded->createdAt->format('Y-m-d'));
    }

    public function testUnknownClassAndTimezoneFallBackAndAreLogged(): void
    {
        $logger = new class implements LoggerInterface {
            public array $errors = [];

            public function log(Throwable|string $error): void
            {
                $this->errors[] = $error;
            }
        };

        $date = DateTimeValue::decode(
            ['__datetime' => '2026-01-01T00:00:00.000000Z', 'tz' => 'Mars/Olympus', 'class' => 'Gone\\Date'],
            '__datetime',
            onFailure: $logger->log(...),
        );

        $this->assertInstanceOf(DateTimeImmutable::class, $date);
        $this->assertSame('UTC', $date->getTimezone()->getName());
        $this->assertCount(2, $logger->errors);
        $this->assertContainsOnlyInstancesOf(DateTimeRestorationFailureException::class, $logger->errors);
    }

    public function testPropertyNamedLikeTheReservedKeyMovesTheKey(): void
    {
        $object = new stdClass();
        $object->__datetime = 'just a text';
        $object->at = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $uuid = $this->storage->store($object);

        $reserved = $this->storage->loadMetadata($uuid)->getReservedDateTimeName();
        $this->assertNotSame('__datetime', $reserved);
        $this->assertArrayHasKey($reserved, $this->rawData($uuid)['at']);

        $loaded = $this->reload($uuid);
        $this->assertSame('just a text', $loaded->__datetime);
        $this->assertInstanceOf(DateTimeImmutable::class, $loaded->at);
    }

    public function testArraysThatOnlyLookLikeDatesStayArrays(): void
    {
        $object = new stdClass();
        $object->almost = ['__datetime' => 'tomorrow', 'tz' => 'UTC', 'class' => DateTimeImmutable::class];
        $object->more = ['__datetime' => '2026-01-01T00:00:00.000000Z', 'tz' => 'UTC', 'class' => DateTimeImmutable::class, 'extra' => 1];

        $loaded = $this->reload($this->storage->store($object));

        $this->assertSame($object->almost, $loaded->almost);
        $this->assertSame($object->more, $loaded->more);
    }

    public function testUtcTextOrderIsTheOrderInTime(): void
    {
        $dates = [
            new DateTimeImmutable('2026-01-01 00:30:00', new DateTimeZone('Europe/Berlin')), // 2025-12-31T23:30Z
            new DateTimeImmutable('2025-12-31 23:45:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2025-12-31 20:00:00', new DateTimeZone('America/New_York')), // 2026-01-01T01:00Z
        ];
        $texts = array_map(DateTimeValue::utc(...), $dates);
        $sorted = $texts;
        sort($sorted, SORT_STRING);

        $this->assertSame([$texts[0], $texts[1], $texts[2]], $sorted);
    }

    public function testYearsOutsideFourDigitsAreKept(): void
    {
        $object = new stdClass();
        $object->ancient = new DateTimeImmutable('-0044-03-15T12:00:00Z');
        $object->future = new DateTimeImmutable('+10000-01-01T00:00:00Z');

        $uuid = $this->storage->store($object);
        $loaded = $this->reload($uuid);

        $this->assertSame('-0044-03-15', $loaded->ancient->format('X-m-d'));
        $this->assertSame('+10000-01-01', $loaded->future->format('X-m-d'));
    }

    public function testDatesStoredAsRecordsOfTheirOwnStillLoad(): void
    {
        // before dates were stored inline, they were records of their own, referenced by the object holding them
        $date = new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('Europe/Berlin'));
        $dateUuid = $this->storage->store($date);

        $holder = new stdClass();
        $holder->untyped = new LazyLoadReference($this->storage, $dateUuid, $holder, ['untyped']);
        $uuid = $this->storage->store($holder);
        $this->assertSame(['__reference' => $dateUuid], $this->rawData($uuid)['untyped']);

        $loaded = $this->reload($uuid);
        $this->assertInstanceOf(LazyLoadReference::class, $loaded->untyped);
        $this->assertEquals($date, $loaded->untyped->getObject());

        // stored again, the loaded date is stored inline
        $this->storage->store($loaded, $uuid);
        $this->assertSame('2026-01-01T09:00:00.000000Z', $this->rawData($uuid)['untyped']['__datetime']);
    }

    public function testDateStoredAsRecordOfItsOwnLoadsIntoTypedProperty(): void
    {
        $dateUuid = $this->storage->store(new DateTimeImmutable('2026-01-01T10:00:00Z'));

        $holder = new stdClass();
        $holder->createdAt = new LazyLoadReference($this->storage, $dateUuid, $holder, ['createdAt']);
        $uuid = $this->storage->store($holder);
        $this->storage->getClassRenameMap()->createAlias(stdClass::class, DatedEvent::class);

        $loaded = $this->reload($uuid);

        $this->assertInstanceOf(DateTimeImmutable::class, $loaded->createdAt);
        $this->assertSame('2026-01-01T10:00:00+00:00', $loaded->createdAt->format(DATE_ATOM));
    }
}

final class DatedEvent
{
    public function __construct(public DateTimeImmutable $createdAt)
    {
    }
}

final class CustomDate extends DateTimeImmutable
{
}

final class SerializedDates
{
    public function __construct(public DateTimeInterface $at)
    {
    }

    public function __serialize(): array
    {
        return ['at' => $this->at];
    }

    public function __unserialize(array $data): void
    {
        $this->at = $data['at'];
    }
}
