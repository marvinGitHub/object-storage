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

## Named configurations

Instead of building lock adapters and caches in code, the settings of a storage can be kept as a named `Configuration`: where the locks are kept, how objects are cached, the settings of the strategy and fsync. A `Registry` keeps configurations by name, assigns them to storages and opens storages with them, so an application only needs the directory of a storage, and optionally the name of a configuration:

```php
use melia\ObjectStorage\Configuration\CacheSettings;
use melia\ObjectStorage\Configuration\Configuration;
use melia\ObjectStorage\Configuration\LockSettings;
use melia\ObjectStorage\Configuration\Registry;

$configurations = new Registry('/etc/my-app/object-storage'); // where the configurations are kept

$configurations->create(new Configuration(
    name: 'shared',
    description: 'several hosts',
    lock: new LockSettings(LockSettings::TYPE_REDIS, 'redis://:%env(REDIS_PASSWORD)%@redis:6379', ttl: 60, prefix: 'shop.'),
    cache: new CacheSettings(CacheSettings::TYPE_APCU, defaultLifetime: 300),
    optimisticLocking: true,
));
$configurations->assign('/var/storage', 'shared');

$storage = $configurations->open('/var/storage');           // with the configuration assigned to the storage
$storage = $configurations->open('/var/storage', 'shared'); // with a named configuration
```

A configuration can also be passed to the constructor; arguments passed explicitly take precedence over it, and a strategy passed explicitly keeps its own settings:

```php
$storage = new ObjectStorage('/var/storage', configuration: $configurations->get('shared'));
$storage->getConfiguration()->name; // 'shared'
```

| Lock backend (`LockSettings`) | Connection | Use when |
|---|---|---|
| `TYPE_FILE` (default) | optional `directory`, by default the `locks` directory of the storage | one host |
| `TYPE_SEMAPHORE` | – (needs `ext-sysvsem`, not on Windows) | one host, no lock files |
| `TYPE_REDIS` | `redis://host:6379` | several hosts |
| `TYPE_MEMCACHED` | `memcached://host:11211` | several hosts; no shared locks, reads lock exclusively |
| `TYPE_PDO` | `mysql:host=db;dbname=app`, `username`, `password` | several hosts with a database; no shared locks |
| `TYPE_DSN` | any connection `symfony/lock` understands, e.g. `postgresql+advisory://…` | other backends |

| Cache (`CacheSettings`) | Shared by |
|---|---|
| `TYPE_MEMORY` (default) | nothing: the memory of the process |
| `TYPE_NONE` | no cache, every load reads the file |
| `TYPE_APCU` | the processes of one PHP server (`ext-apcu`) |
| `TYPE_FILESYSTEM` | the processes of one host |
| `TYPE_REDIS`, `TYPE_MEMCACHED` | all hosts |

Things to know:

- **Names** are unique, also when several processes create a configuration at the same time; they consist of lowercase letters, digits, `_` and `-`. Configurations are objects of an object storage of their own in the directory of the registry.
- **The assignment** is the file `configuration.json` in the directory of the storage. A storage assigned a configuration that was deleted can't be opened (`ConfigurationNotFoundException`) until it is assigned another one.
- **All processes of a storage must use the same locks.** Change the lock backend only while nothing else uses the storage. On several hosts, use the same `prefix` (and cache `namespace`) everywhere: by default the cache namespace is derived from the directory of the storage, which may differ between hosts.
- **Secrets**: `%env(NAME)%` in connections, directories and passwords is replaced by the environment variable when the storage is opened, so the secret isn't stored in the configuration. Descriptions mask passwords.
- **Internal storages** of a storage (search indexes, stepped maintenance) use the same locks, without a cache and with the default strategy.
- **Checking**: `(new Factory())->check($configuration, $storageDir)` takes a lock and writes, reads and removes a cache entry, and throws a `ConfigurationException` with the reason if a backend doesn't work.
- **Not covered**: the logger, the event dispatcher and strategies of their own are objects; pass them to the constructor. The policies `CALLBACK` need a strategy of its own as well.

The [viewer](maintenance.md#the-web-viewer) manages configurations under **Configurations** (create, edit, check, delete) and assigns one to a storage on its page of classes. The [CLI](cli.md#configurations) opens storages with their assigned configuration and manages configurations with the `configuration:*` commands.

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

### Child deletion: whether referenced objects are deleted along

```php
use melia\ObjectStorage\Strategy\Policy\ChildDeletion;

$storage->getStrategy()->setPolicyChildDeletion(ChildDeletion::ALWAYS);
```

| Policy | When an object is deleted, the objects it references are … | Use when |
|---|---|---|
| `NEVER` (default) | kept | referenced objects are shared or live on their own |
| `ALWAYS` | deleted as well, recursively | the referencing object owns its graph, e.g. an order and its lines |
| `CALLBACK` | deleted if `shouldDeleteChild()` of the strategy returns `true` | only some references are owned, see below |

The referenced objects are deleted after the object itself, each under its own lock. Circular references are followed only once, and objects already deleted are skipped. If some of them can't be deleted, the others are still deleted, and `delete()` throws an `ObjectDeletionFailureException` afterwards.

Things to know:

- **Shared objects are deleted too.** The storage doesn't know which other objects reference an object, so `ALWAYS` also deletes an object that another object still references, which then holds a reference to a deleted object (loaded as `null`). Use `ALWAYS` only for graphs owned by one object, and `CALLBACK` otherwise.
- **Transactions** restore only the object passed to `delete()` on rollback, not the referenced objects deleted along with it.
- A crash in the middle leaves referenced objects that are no longer referenced, never references to deleted objects.

With `CALLBACK`, the strategy decides per reference. It gets the class names and the path of the reference within the stored data of the deleted object, e.g. `['lines', 0]`; the referenced objects aren't loaded. If the callback throws, the referenced object is kept.

```php
use melia\ObjectStorage\Strategy\Policy\ChildDeletion;
use melia\ObjectStorage\Strategy\Standard;

final class OrderStrategy extends Standard
{
    public function __construct()
    {
        $this->setPolicyChildDeletion(ChildDeletion::CALLBACK);
    }

    // delete the lines of an order, but not its customer
    public function shouldDeleteChild(string $parentUuid, string $parentClassName, string $childUuid, string $childClassName, array $path): bool
    {
        return $parentClassName === Order::class && $childClassName === OrderLine::class;
    }
}
```

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
