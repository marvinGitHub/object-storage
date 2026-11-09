# Serialization

Objects are stored as JSON. This page explains which objects and values can be stored and how to control what is stored.

## What is stored

- **All properties of the class**, public, protected and private, and the public and protected properties inherited from parent classes. **Private properties of parent classes are not stored**; make them protected, or implement `__serialize()` (see below). Uninitialized typed properties are skipped.
- **Scalars, `null` and arrays** as they are; arrays may be nested and contain objects.
- **Dates** (`DateTimeInterface`) inline, as values of the object holding them, see [Dates](#dates).
- **Other objects** as references to records of their own, see [Concepts](concepts.md#objects-reference-each-other-object-graphs).
- **`readonly` properties** are stored and restored.
- **Dynamic properties** are stored too. To restore them, the class needs the attribute `#[\AllowDynamicProperties]` (or be `stdClass`); otherwise loading throws a `DynamicPropertiesNotAllowedException`.

On load, objects are created without calling their constructor, and the properties are set directly.

## Dates

Instances of `DateTimeInterface` (`DateTimeImmutable`, `DateTime` and their subclasses) are values, not objects of their own: they are stored in the object holding them, in properties, arrays and the data of `__serialize()`:

```json
"createdAt": {
  "__datetime": "2026-11-09T09:00:00.123456Z",
  "tz": "Europe/Berlin",
  "class": "DateTimeImmutable"
}
```

- **`__datetime`** is the point in time in UTC with microseconds. For the years 0000 to 9999 it always has the same length, so its order as text is its order in time. Other years are written in the expanded form of ISO 8601 (`-0044-03-15…`, `+10000-01-01…`).
- **`tz`** is the name of the time zone: an identifier (`Europe/Berlin`), an offset (`+05:30`) or an abbreviation (`CEST`), as returned by `getTimezone()->getName()`. The date is loaded in this time zone, so it is restored exactly, also across daylight saving time.
- **`class`** is the class that is loaded, created with `createFromInterface()`. The [rename map](#renamed-and-removed-classes) applies. A class that no longer exists, or isn't a date, is loaded as `DateTimeImmutable`, an unknown time zone as UTC; both are logged (`DateTimeRestorationFailureException`). Properties of subclasses of their own (e.g. the settings of Carbon) aren't stored.
- **A declared type wins**: a stored `DateTime` loaded into a property declared as `DateTimeImmutable` is converted, and vice versa.
- **No identity**: two objects holding the same `DateTime` instance get two equal dates when loaded. For a mutable `DateTime` that is shared on purpose, wrap it in an object of its own.
- **The key `__datetime`** is reserved like `__reference`: if the object has a property with that name, another key is used and recorded in the metadata (`reservedDateTimeName`). Only arrays with exactly these three keys and a point in time in this form are read as dates.

Dates stored before 1.0 as records of their own (referenced with `__reference`) still load as before; storing the object holding them again stores them inline (a `LazyLoadReference` in a property without type only once it was loaded). The old records stay, see [UPGRADE.md](../UPGRADE.md).

## Values that can't be stored

| Value | Behavior |
|---|---|
| resources (files, sockets, connections) | skipped and logged (`ResourceSerializationNotSupportedException`) |
| closures | skipped and logged (`ClosureSerializationNotSupportedException`) |
| generators and iterators | iterated once and stored as an array; loading returns the array, not a generator |

Store what is needed to recreate such values instead, e.g. a file path instead of a file handle, and recreate them in `__unserialize()`. Don't store infinite or very large generators: they are iterated completely.

## Controlling what is stored

Implement `__serialize()` and `__unserialize()` to decide yourself which data is stored and how the object is restored:

```php
final class Report
{
    private ?array $rows = null; // expensive, recreated on demand

    public function __construct(
        private string $query,
        private DateTimeImmutable $createdAt,
    ) {}

    public function __serialize(): array
    {
        return [
            'query' => $this->query,
            'createdAt' => $this->createdAt->format(DATE_ATOM),
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->query = $data['query'];
        $this->createdAt = new DateTimeImmutable($data['createdAt']);
        $this->rows = null;
    }
}
```

- The array returned by `__serialize()` may use string or int keys (e.g. `ArrayObject`); `__unserialize()` receives it as stored.
- Objects in the array returned by `__serialize()`, also in nested arrays, are stored on their own and passed to `__unserialize()` loaded again, as PHP's `unserialize()` does. They are loaded right away, not lazily. In a reference cycle (A references B, B references A), `__unserialize()` of B gets the instance of A that is still being restored. A referenced object that was deleted or has expired is passed as `null`.
- `__sleep()` and `__wakeup()` are not used.
- To exclude properties of many classes at once, use a [property persistence policy](configuration.md#property-persistence-and-hydration-which-properties-are-written-and-loaded) instead.

## Type conversion on load

JSON doesn't distinguish all PHP types. When a stored scalar doesn't match the declared type of a property, it is converted, as long as no information is lost: `"5"` becomes `5` for an `int` property, `2.0` becomes `2`. A lossy conversion, e.g. `"abc"` or `2.5` into an `int` property, fails with a `TypeConversionFailureException`. This mostly matters after changing the type of a property.

## Renamed and removed classes

The class name is stored with every object. If a class is renamed, map the old name to the new one, and stored objects are loaded as instances of the new class:

```php
use melia\ObjectStorage\Runtime\ClassRenameMap;

$map = new ClassRenameMap();
$map->createAlias(\Legacy\Customer::class, \App\Customer\Customer::class);
$storage->setClassRenameMap($map);
```

If a stored class doesn't exist at all, the object is still loaded: an alias of a placeholder class is created under the old name, and the event `CLASS_ALIAS_CREATED` is dispatched. This keeps old data readable, but the object has no methods; map the class or migrate the data.

See [Renaming classes](examples/renaming-classes.md) for a complete example.
