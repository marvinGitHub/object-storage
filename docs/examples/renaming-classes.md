# Example: renaming classes

An application stores its customers as `Client` objects. In a refactoring the class is renamed to `Customer`. The stored data still names the old class, so it has to stay loadable, and should eventually be migrated.

**You will learn:** how class names are stored, `ClassRenameMap`, migrating objects to a new class, what happens with unknown classes.

## Version 1: storing clients

```php
<?php

use melia\ObjectStorage\ObjectStorage;

require __DIR__ . '/vendor/autoload.php';

class Client
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}

$storage = new ObjectStorage(__DIR__ . '/var/crm');
$storage->store(new Client('Ada Lovelace', 'ada@example.com'));
$storage->store(new Client('Grace Hopper', 'grace@example.com'));
```

Every object's metadata holds its class name, here `Client`, and the class index lists the objects per class.

## Version 2: the class is renamed

The new code only knows `Customer`. Map the old class name to the new one, and stored `Client` objects are loaded as `Customer`:

```php
<?php

use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Runtime\ClassRenameMap;

require __DIR__ . '/vendor/autoload.php';

class Customer
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}

$storage = new ObjectStorage(__DIR__ . '/var/crm');

$renames = new ClassRenameMap();
$renames->createAlias('Client', Customer::class);
$storage->setClassRenameMap($renames);

foreach ($storage->list('Client') as $uuid) {
    $customer = $storage->load($uuid);
    echo $customer::class, ': ', $customer->name, PHP_EOL; // Customer: Ada Lovelace, …
}
```

Configure the map once at startup, wherever the storage is created. Use fully qualified class names for namespaced classes.

## Migrating the data

The map keeps old data loadable, but the objects are still stored as `Client`: `list(Customer::class)` doesn't find them yet. Storing each object again writes it under its new class name and moves it in the class index:

```php
foreach ($storage->list('Client') as $uuid) {
    $storage->store($storage->load($uuid), $uuid);
}

echo iterator_count($storage->list(Customer::class)), ' customers', PHP_EOL; // 2 customers
echo iterator_count($storage->list('Client')), ' clients', PHP_EOL;          // 0 clients
```

Every migrated object dispatches the event `CLASSNAME_CHANGED`. Once all objects are migrated, the map can be removed.

For large storages, run the migration in batches or as a CLI command, and stop other processes that write to the storage while it runs.

## Without a mapping

If a stored class doesn't exist and isn't mapped, the object is still loaded: a placeholder class is created under the old name, and its properties are set as dynamic properties. The event `CLASS_ALIAS_CREATED` is dispatched. The data stays accessible (`$client->name` works), but the object has none of its methods. Treat it as a signal to add the mapping.

## Other refactorings

- **Added properties** keep their default value when old objects are loaded, if the default is declared on the property (`public string $plan = 'free';`). Objects are loaded without calling the constructor, so the default of a promoted constructor parameter is not applied, and the property stays uninitialized.
- **Removed properties** cause an error on load if the class doesn't allow dynamic properties; ignore them with a [hydration policy](../configuration.md#property-persistence-and-hydration-which-properties-are-written-and-loaded).
- **Changed property types** are converted as long as no information is lost, see [Serialization](../serialization.md#type-conversion-on-load).
