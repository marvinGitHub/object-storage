# Configuration

`new ObjectStorage($directory)` works without any further configuration. Everything else is optional and can be passed to the constructor as named arguments:

```php
use melia\ObjectStorage\ObjectStorage;

$storage = new ObjectStorage(
    storageDir: __DIR__ . '/var/storage',
    logger: $logger,               // where errors are reported, see "Logging"
    lockAdapter: $lockAdapter,     // file locks by default, see Concurrency
    stateHandler: null,            // safe mode handling, created by default
    eventDispatcher: $dispatcher,  // created by default, see Events
    cache: $cache,                 // PSR-16 cache for objects, in memory by default
    metadataCache: $metadataCache, // PSR-16 cache for metadata, in memory by default
    adapter: null,                 // file system access, the real file system by default
    strategy: null,                // serialization rules, see "Strategy"
);
```

## Caches

Objects and their metadata are cached in memory by default. Any PSR-16 cache can be used instead, e.g. to share cached objects between requests with APCu or Redis via `symfony/cache`:

```php
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Psr16Cache;

$storage = new ObjectStorage(
    __DIR__ . '/var/storage',
    cache: new Psr16Cache(new ApcuAdapter('objects')),
);
```

A shared cache isn't updated when another process changes an object without going through the same cache, see [Concurrency](concurrency.md#multiple-processes).

## Writing without fsync

Writes are flushed to disk by default, which makes them slower. Turn it off where durability doesn't matter, see [Durability](durability.md#fsync):

```php
$storage->getWriter()->setSyncEnabled(false);
```

## Logging

Errors that don't stop an operation — a failing cache, an unreadable file during a scan — are reported to the logger. Implement `LoggerInterface` to connect your logging, e.g. a PSR-3 logger:

```php
use melia\ObjectStorage\Logger\LoggerInterface;
use Psr\Log\LoggerInterface as PsrLogger;

final class PsrLoggerBridge implements LoggerInterface
{
    public function __construct(private PsrLogger $logger) {}

    public function log(Throwable $error): void
    {
        $this->logger->error($error->getMessage(), ['exception' => $error]);
    }
}

$storage = new ObjectStorage(__DIR__ . '/var/storage', logger: new PsrLoggerBridge($psrLogger));
```

## Strategy

The strategy defines how objects are serialized and hydrated. The default, `Standard`, can be configured:

```php
$strategy = $storage->getStrategy();

$strategy->setShardDepth(3);               // directory levels for the object files (default 2)
$strategy->setMaxDepth(256);               // maximum nesting depth of an object graph (default 512)
$strategy->enableLifetimeInheritance();    // referenced objects expire with the referencing object
$strategy->disableChecksumValidation();    // not recommended, see Durability
```

The shard depth determines where files are looked up. Change it only for an empty storage, or move the files afterwards with `vendor/bin/object-storage maintenance:rebuild-shards`, see [Maintenance](maintenance.md).

## Persistence policies

Policies decide what is written and loaded. They are set on the strategy.

### Child persistence: when referenced objects are written

```php
use melia\ObjectStorage\Strategy\Policy\ChildPersistence;

$storage->getStrategy()->setPolicyChildPersistence(ChildPersistence::ALWAYS);
```

| Policy | Referenced objects are written … | Use when |
|---|---|---|
| `IF_NOT_EXIST` (default) | only if they don't exist yet | referenced objects are created along with their parent, but updated on their own |
| `ALWAYS` | every time the referencing object is stored | the referencing object owns its graph, e.g. an order and its lines |
| `NEVER` | never; references point to UUIDs only | referenced objects are always stored explicitly |
| `CALLBACK` | if `shouldPersistChild()` of the strategy returns `true` | the decision depends on the object, see below |

### Property persistence and hydration: which properties are written and loaded

By default all properties are written and loaded. To decide per property, extend the strategy and use the `CALLBACK` policies:

```php
use melia\ObjectStorage\Reflection\Reflection;
use melia\ObjectStorage\Strategy\Policy\ChildPersistence;
use melia\ObjectStorage\Strategy\Policy\PropertyHydration;
use melia\ObjectStorage\Strategy\Policy\PropertyPersistence;
use melia\ObjectStorage\Context\GraphBuilderContext;
use melia\ObjectStorage\Strategy\Standard;

final class AppStrategy extends Standard
{
    public function __construct()
    {
        $this->setPolicyPropertyPersistence(PropertyPersistence::CALLBACK);
        $this->setPolicyPropertyHydration(PropertyHydration::CALLBACK);
        $this->setPolicyChildPersistence(ChildPersistence::CALLBACK);
    }

    // don't store runtime state such as caches
    public function shouldPersistProperty(Reflection $reflection, string $propertyName, mixed $value): bool
    {
        return !str_starts_with($propertyName, 'runtime');
    }

    // ignore properties that no longer exist in the class
    public function shouldHydrateProperty(Reflection $reflection, string $propertyName, mixed $value): bool
    {
        return property_exists($reflection->getTarget(), $propertyName);
    }

    // write referenced objects that are new or explicitly marked as changed
    public function shouldPersistChild(GraphBuilderContext $context, object $child, string $childUuid, bool $childExists, array $path): bool
    {
        return !$childExists || ($child->changed ?? false);
    }
}

$storage = new ObjectStorage(__DIR__ . '/var/storage', strategy: new AppStrategy());
```

Objects that implement `__serialize()` / `__unserialize()` decide themselves what is stored, see [Serialization](serialization.md#controlling-what-is-stored).

## File system access

All file access goes through an IO adapter (`AdapterInterface`), by default `RealAdapter`, the real file system. A custom adapter, e.g. for tests or instrumentation, is used for reading and writing objects as well:

```php
$storage = new ObjectStorage(__DIR__ . '/var/storage', adapter: new MyAdapter());
```
