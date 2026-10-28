# Serialization

Objects are stored as JSON. This page explains which objects and values can be stored and how to control what is stored.

## What is stored

- **All properties of the class**, public, protected and private, and the public and protected properties inherited from parent classes. **Private properties of parent classes are not stored**; make them protected, or implement `__serialize()` (see below). Uninitialized typed properties are skipped.
- **Scalars, `null` and arrays** as they are; arrays may be nested and contain objects.
- **Objects** as references to records of their own, see [Concepts](concepts.md#objects-reference-each-other-object-graphs).
- **`readonly` properties** are stored and restored.
- **Dynamic properties** are stored too. To restore them, the class needs the attribute `#[\AllowDynamicProperties]` (or be `stdClass`); otherwise loading throws a `DynamicPropertiesNotAllowedException`.

On load, objects are created without calling their constructor, and the properties are set directly.

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

- The keys of the array returned by `__serialize()` have to be strings.
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
