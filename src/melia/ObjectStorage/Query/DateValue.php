<?php

namespace melia\ObjectStorage\Query;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use melia\ObjectStorage\Serialization\DateTimeValue;
use Throwable;

/**
 * A date in a query: a date as stored (see Serialization\DateTimeValue), the result of a date function, or a text
 * compared with a date. Dates are compared by their point in time; their time zone and class only matter when they
 * are written (INSERT, UPDATE). In results, a date is its point in time in UTC as stored
 * ("2026-11-09T09:00:00.123456Z").
 *
 * @internal Not part of the public API; may change in any release.
 */
final readonly class DateValue
{
    /** seconds since 1970-01-01 UTC */
    public int $seconds;

    /** microseconds of the second */
    public int $microseconds;

    /** the point in time in UTC as stored */
    public string $utc;

    public function __construct(public DateTimeImmutable $date, public string $class = DateTimeImmutable::class)
    {
        $this->seconds = $date->getTimestamp();
        $this->microseconds = (int)$date->format('u');
        $this->utc = DateTimeValue::utc($date);
    }

    public static function of(DateTimeInterface $date): self
    {
        return new self(DateTimeImmutable::createFromInterface($date), $date::class);
    }

    /**
     * A date as stored (an array marked with the reserved key, or the default one); null if it isn't one. Its
     * class isn't loaded.
     */
    public static function fromStored(mixed $value, ?string $reserved = null): ?self
    {
        $date = DateTimeValue::pointInTime($value, $reserved);
        return null === $date ? null : new self($date, $value[DateTimeValue::KEY_CLASS]);
    }

    /**
     * A date written as text (see DateTimeValue::parse()), in the given time zone without Z or offset.
     */
    public static function fromText(string $text, string $timezone = 'UTC'): ?self
    {
        static $parsed = [];
        $key = $timezone . "\0" . $text;
        if (array_key_exists($key, $parsed)) {
            return $parsed[$key];
        }
        if (count($parsed) > 1000) {
            $parsed = [];
        }
        $date = DateTimeValue::parse($text, $timezone);
        return $parsed[$key] = null === $date ? null : new self($date);
    }

    /**
     * A value as a date: a date, or a text that is one (see fromText()); null otherwise.
     */
    public static function from(mixed $value): ?self
    {
        return match (true) {
            $value instanceof self => $value,
            is_string($value) => self::fromText($value),
            default => null,
        };
    }

    /** the order in time: -1, 0 or 1 */
    public function compare(self $other): int
    {
        return [$this->seconds, $this->microseconds] <=> [$other->seconds, $other->microseconds];
    }

    /** seconds since 1970-01-01 UTC, with the fraction */
    public function timestamp(): int|float
    {
        return DateTimeValue::timestamp($this->date);
    }

    /** the date in a time zone (UTC if none is given) */
    public function in(?string $timezone): DateTimeImmutable
    {
        return $this->date->setTimezone(new DateTimeZone($timezone ?? 'UTC'));
    }

    /** the date as stored, with the reserved key */
    public function stored(string $reserved): array
    {
        return [
            $reserved => $this->utc,
            DateTimeValue::KEY_TIMEZONE => $this->date->getTimezone()->getName(),
            DateTimeValue::KEY_CLASS => $this->class,
        ];
    }

    /** the same point in time and time zone as another class (e.g. the declared one) */
    public function as(string $class): self
    {
        return new self($this->date, $class);
    }
}
