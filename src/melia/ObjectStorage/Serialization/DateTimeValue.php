<?php

namespace melia\ObjectStorage\Serialization;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use melia\ObjectStorage\Exception\DateTimeRestorationFailureException;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\Runtime\ClassRenameMap;
use Throwable;

/**
 * Stores instances of DateTimeInterface inline, as a value of the object that holds them, instead of as objects of
 * their own:
 *
 *     {"__datetime": "2026-11-09T09:00:00.123456Z", "tz": "Europe/Berlin", "class": "DateTimeImmutable"}
 *
 * The point in time is in UTC with microseconds and always has the same length for the years 0000 to 9999, so its
 * order as text is its order in time. Other years use the expanded ISO 8601 form (-0044, +10000). "tz" is the name
 * of the time zone (an identifier, an offset or an abbreviation), "class" the class that is restored.
 */
final class DateTimeValue
{
    // x: four digits for the years 0000 to 9999, signed and expanded only for the others (-0044, +10000)
    public const string FORMAT = 'x-m-d\TH:i:s.u\Z';
    public const string KEY_TIMEZONE = 'tz';
    public const string KEY_CLASS = 'class';

    private const string PATTERN = '/^[+-]?\d{4,}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/';

    /** a date written as text: date, optional time (with seconds and fraction), optional Z or offset */
    private const string TEXT_PATTERN = '/^([+-]?\d{4,})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,6}))?)?)?\s*(Z|[+-]\d{2}:?\d{2})?$/i';

    /**
     * Encodes a date as stored.
     *
     * @param DateTimeInterface $value The date to encode.
     * @param string $reserved The reserved key marking a date (see Metadata::getReservedDateTimeName()).
     * @return array<string, string>
     */
    public static function encode(DateTimeInterface $value, string $reserved): array
    {
        return [
            $reserved => self::utc($value),
            self::KEY_TIMEZONE => $value->getTimezone()->getName(),
            self::KEY_CLASS => $value::class,
        ];
    }

    /**
     * The point in time of a date in UTC, as stored.
     */
    public static function utc(DateTimeInterface $value): string
    {
        return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }

    /**
     * Whether a stored value is an encoded date: an array with exactly the reserved key, "tz" and "class", and a point
     * in time in the stored format. Anything else, e.g. an array that only happens to use the reserved key, is not.
     */
    public static function isEncoded(mixed $value, string $reserved): bool
    {
        return is_array($value)
            && 3 === count($value)
            && isset($value[$reserved], $value[self::KEY_TIMEZONE], $value[self::KEY_CLASS])
            && is_string($value[$reserved])
            && is_string($value[self::KEY_TIMEZONE])
            && is_string($value[self::KEY_CLASS])
            && 1 === preg_match(self::PATTERN, $value[$reserved]);
    }

    /**
     * Whether a value is an encoded date with the given key or the default one: values read through references
     * come from objects whose key is mostly the default.
     */
    public static function isEncodedAny(mixed $value, ?string $reserved = null): bool
    {
        return (null !== $reserved && self::isEncoded($value, $reserved)) || self::isEncoded($value, Metadata::RESERVED_DATETIME_NAME_DEFAULT);
    }

    /**
     * The point in time and time zone of an encoded date (see isEncodedAny()) as a DateTimeImmutable, without
     * loading its class (e.g. where classes aren't available); null if it isn't one.
     */
    public static function pointInTime(mixed $value, ?string $reserved = null): ?DateTimeImmutable
    {
        $key = null !== $reserved && self::isEncoded($value, $reserved) ? $reserved : Metadata::RESERVED_DATETIME_NAME_DEFAULT;
        if (!self::isEncoded($value, $key)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat(self::FORMAT, $value[$key], new DateTimeZone('UTC'));
        if (false === $date) {
            return null;
        }
        try {
            return $date->setTimezone(new DateTimeZone($value[self::KEY_TIMEZONE]));
        } catch (Throwable) {
            return $date;
        }
    }

    /**
     * A date written as text in the form of ISO 8601: '2026-01-01', '2026-01-01 10:00', '2026-01-01T10:00:00.5Z',
     * '2026-01-01T10:00:00+01:00'. Without Z or offset it is in the given time zone, UTC by default. Null for other
     * texts and for dates that don't exist ('2026-02-30').
     */
    public static function parse(string $text, string $timezone = 'UTC'): ?DateTimeImmutable
    {
        if (1 !== preg_match(self::TEXT_PATTERN, $text, $match)) {
            return null;
        }
        [, $year, $month, $day] = $match;
        $hour = $match[4] ?? '';
        $minute = $match[5] ?? '';
        $second = $match[6] ?? '';
        $fraction = $match[7] ?? '';
        $zone = $match[8] ?? '';

        // the leap years repeat every 400 years: checkdate() takes years from 1 only
        if (!checkdate((int)$month, (int)$day, (int)$year % 400 + 400) || (int)$hour > 23 || (int)$minute > 59 || (int)$second > 59) {
            return null;
        }
        try {
            $date = DateTimeImmutable::createFromFormat(
                'X-m-d H:i:s.u',
                sprintf('%s%s-%s-%s %s:%s:%s.%s', '-' === $year[0] ? '' : '+', ltrim($year, '+'), $month, $day,
                    '' === $hour ? '00' : $hour, '' === $minute ? '00' : $minute, '' === $second ? '00' : $second, str_pad($fraction, 6, '0')),
                new DateTimeZone('' === $zone ? $timezone : ('z' === strtolower($zone) ? 'UTC' : $zone)),
            );
        } catch (Throwable) {
            return null;
        }
        return false === $date ? null : $date;
    }

    /**
     * The seconds since 1970-01-01 UTC, with the microseconds as fraction (an int without).
     */
    public static function timestamp(DateTimeInterface $value): int|float
    {
        $micro = (int)$value->format('u');
        return 0 === $micro ? $value->getTimestamp() : $value->getTimestamp() + $micro / 1_000_000;
    }

    /**
     * Restores an encoded date (see isEncoded()).
     *
     * The class is looked up in the rename map. If it doesn't exist (anymore) or can't be created from another date,
     * a DateTimeImmutable is returned; an unknown time zone leaves the date in UTC. Both are passed to $onFailure.
     *
     * @param array $value The encoded date.
     * @param string $reserved The reserved key marking a date.
     * @param ClassRenameMap|null $renameMap The map of renamed classes.
     * @param callable(Throwable): void|null $onFailure Called with what couldn't be restored as stored.
     * @throws DateTimeRestorationFailureException If the point in time can't be read.
     */
    public static function decode(array $value, string $reserved, ?ClassRenameMap $renameMap = null, ?callable $onFailure = null): DateTimeInterface
    {
        $utc = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat(self::FORMAT, $value[$reserved], $utc);
        if (false === $date) {
            throw new DateTimeRestorationFailureException(sprintf('Invalid point in time "%s"', $value[$reserved]));
        }

        try {
            $date = $date->setTimezone(new DateTimeZone($value[self::KEY_TIMEZONE]));
        } catch (Throwable $e) {
            $onFailure && $onFailure(new DateTimeRestorationFailureException(
                message: sprintf('Unknown time zone "%s", the date is restored in UTC', $value[self::KEY_TIMEZONE]),
                previous: $e,
            ));
        }

        $className = $value[self::KEY_CLASS];
        $className = $renameMap?->getAlias($className) ?? $className;

        return self::convert($date, $className, $onFailure);
    }

    /**
     * Converts a date to the given class, e.g. a DateTime for a property declared as DateTimeImmutable. Classes that
     * aren't dates or can't be created from another date give a DateTimeImmutable.
     *
     * @param callable(Throwable): void|null $onFailure Called if the class can't be used.
     */
    public static function convert(DateTimeInterface $date, string $className, ?callable $onFailure = null): DateTimeInterface
    {
        if ($date::class === $className) {
            return $date;
        }

        if (class_exists($className) && (is_a($className, DateTimeImmutable::class, true) || is_a($className, DateTime::class, true))) {
            try {
                return $className::createFromInterface($date);
            } catch (Throwable $e) {
                $onFailure && $onFailure(new DateTimeRestorationFailureException(
                    message: sprintf('Unable to create a date of class "%s", restored as DateTimeImmutable', $className),
                    previous: $e,
                ));
                return DateTimeImmutable::createFromInterface($date);
            }
        }

        $onFailure && $onFailure(new DateTimeRestorationFailureException(
            sprintf('Class "%s" is no date class (anymore), restored as DateTimeImmutable', $className)
        ));

        return DateTimeImmutable::createFromInterface($date);
    }
}
