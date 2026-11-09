<?php

namespace melia\ObjectStorage\Metadata;

/**
 * The reserved names of the stored data of an object: keys that mark values the storage writes itself, by kind
 * (a reference is {"__reference": uuid}, a date {"__datetime": …, "tz": …, "class": …}). A property of the object
 * with such a name would be taken for a marked value, so another name is chosen for that object
 * (reserveNamesAvoiding()) and recorded in its metadata ("reserved<Kind>Name", e.g. "reservedReferenceName").
 *
 * A kind is added by adding it to RESERVED_NAME_DEFAULTS.
 */
trait ReservedNamesTrait
{
    public const string RESERVED_REFERENCE = 'reference';
    public const string RESERVED_DATETIME = 'dateTime';

    public const string RESERVED_REFERENCE_NAME_DEFAULT = '__reference';
    public const string RESERVED_DATETIME_NAME_DEFAULT = '__datetime';

    /** kind => the name used unless the object has a property with it */
    public const array RESERVED_NAME_DEFAULTS = [
        self::RESERVED_REFERENCE => self::RESERVED_REFERENCE_NAME_DEFAULT,
        self::RESERVED_DATETIME => self::RESERVED_DATETIME_NAME_DEFAULT,
    ];

    /** @var array<string, string> kind => name, if not the default */
    private array $reservedNames = [];

    /**
     * The reserved name of a kind (RESERVED_REFERENCE, RESERVED_DATETIME).
     *
     * @throws \InvalidArgumentException For an unknown kind.
     */
    public function getReservedName(string $kind): string
    {
        return $this->reservedNames[$kind] ?? static::RESERVED_NAME_DEFAULTS[$kind] ?? throw new \InvalidArgumentException(sprintf('Unknown kind of reserved name "%s"', $kind));
    }

    /**
     * Sets the reserved name of a kind.
     *
     * @throws \InvalidArgumentException For an unknown kind.
     */
    public function setReservedName(string $kind, string $name): void
    {
        if (!isset(static::RESERVED_NAME_DEFAULTS[$kind])) {
            throw new \InvalidArgumentException(sprintf('Unknown kind of reserved name "%s"', $kind));
        }
        $this->reservedNames[$kind] = $name;
    }

    /**
     * The reserved names of all kinds.
     *
     * @return array<string, string> kind => name
     */
    public function getReservedNames(): array
    {
        return $this->reservedNames + static::RESERVED_NAME_DEFAULTS;
    }

    /**
     * Chooses reserved names none of the given properties has (keeping the names that don't collide), and records
     * them.
     *
     * @param list<string|int> $propertyNames The names of the properties of the object.
     * @return array<string, string> kind => name
     */
    public function reserveNamesAvoiding(array $propertyNames): array
    {
        $this->reservedNames = static::chooseReservedNames($this->getReservedNames(), $propertyNames);
        return $this->reservedNames;
    }

    /**
     * Reserved names none of the given properties has, all different: a name that collides is replaced by a unique
     * one starting with the default of its kind.
     *
     * @param array<string, string> $names kind => the name before
     * @param list<string|int> $propertyNames The names of the properties of the object.
     * @return array<string, string> kind => name
     */
    public static function chooseReservedNames(array $names, array $propertyNames): array
    {
        $taken = array_fill_keys(array_map('strval', $propertyNames), true);
        $chosen = [];
        foreach ($names + static::RESERVED_NAME_DEFAULTS as $kind => $name) {
            while (isset($taken[$name])) {
                $name = uniqid(static::RESERVED_NAME_DEFAULTS[$kind] ?? '__reserved', true);
            }
            $taken[$name] = true;
            $chosen[$kind] = $name;
        }
        return $chosen;
    }

    // ---- shortcuts and the stored form ----

    public function getReservedReferenceName(): string
    {
        return $this->getReservedName(self::RESERVED_REFERENCE);
    }

    public function setReservedReferenceName(string $reservedReferenceName): void
    {
        $this->setReservedName(self::RESERVED_REFERENCE, $reservedReferenceName);
    }

    /** the key marking a date stored inline (see Serialization\DateTimeValue) */
    public function getReservedDateTimeName(): string
    {
        return $this->getReservedName(self::RESERVED_DATETIME);
    }

    public function setReservedDateTimeName(string $reservedDateTimeName): void
    {
        $this->setReservedName(self::RESERVED_DATETIME, $reservedDateTimeName);
    }

    /** the key of a kind in the stored metadata: "reserved<Kind>Name" */
    private static function reservedNameKey(string $kind): string
    {
        return 'reserved' . ucfirst($kind) . 'Name';
    }

    /**
     * The reserved names as stored in the metadata.
     *
     * @return array<string, string> e.g. ['reservedReferenceName' => '__reference', 'reservedDateTimeName' => '__datetime']
     */
    protected function reservedNamesToArray(): array
    {
        $stored = [];
        foreach ($this->getReservedNames() as $kind => $name) {
            $stored[self::reservedNameKey($kind)] = $name;
        }
        return $stored;
    }

    /**
     * Reads the reserved names from stored metadata; kinds not stored (older metadata) keep their defaults.
     */
    protected function reservedNamesFromArray(array $data): void
    {
        foreach (array_keys(static::RESERVED_NAME_DEFAULTS) as $kind) {
            $key = self::reservedNameKey($kind);
            if (isset($data[$key]) && is_string($data[$key])) {
                $this->setReservedName($kind, $data[$key]);
            }
        }
    }
}
