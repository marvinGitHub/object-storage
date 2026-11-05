# Unique indexes

A unique index makes sure no two objects of a class have the same values — like a `UNIQUE` constraint of a database —, and finds objects by those values without a search.

```php
use melia\ObjectStorage\Unique\Definition;
use melia\ObjectStorage\Unique\Exception\UniqueConstraintViolationException;
use melia\ObjectStorage\Unique\Manager;

$unique = new Manager($storage);

// once, e.g. in a setup script (or in the viewer)
if (!$unique->has('customer-email')) {
    $unique->create(new Definition('customer-email', Customer::class, 'email', caseInsensitive: true));
}

// in every process writing to the storage
$unique->attach();

$storage->store(new Customer('ada@example.com'));

try {
    $storage->store(new Customer(' ADA@example.com '));
} catch (UniqueConstraintViolationException $e) {
    $e->getIndexName(); // 'customer-email'
    $e->getValues();    // ['email' => ' ADA@example.com ']
    $e->getOwner();     // the UUID of the customer having it
}

$unique->find('customer-email', 'Ada@Example.com');      // the customer, or null
$unique->findOwner('customer-email', 'Ada@Example.com'); // its UUID, without loading it
```

## Defining an index

| Parameter | Meaning |
|---|---|
| `name` | unique per storage: lowercase letters, digits, `_` and `-` |
| `className` | the class of the objects (not its subclasses) |
| `paths` | the path of the value in the stored data, e.g. `email` or `address.email`; several paths for a combination: `['customer', 'number']` |
| `caseInsensitive` | strings differing in case and surrounding spaces are the same, e.g. for emails |

- Paths work like the [fields of a search index](search.md#defining-an-index): they lead into the stored data, a referenced object counts by its UUID. `['customer', 'number']` makes an order number unique per customer.
- A path has to lead to a single value. Lists can't be unique: `create()` refuses an index none of whose objects has a single value at its paths.
- **Missing values**: as in SQL, an object with a `null` value at a path has no key, so any number of objects may lack it.
- **Values are compared as stored**, numbers and numeric strings alike: `12`, `12.0` and `"12"` are the same value.

## What is checked

With `attach()`, every write of an object of the class is checked before its data is written, under its lock:

- `store()` — a violation throws a `UniqueConstraintViolationException` (a `ConstraintViolationException`, which is an `ObjectSavingFailureException`), nothing is written, and the event `CONSTRAINT_VIOLATION` is dispatched. Objects referenced by the object and written along before it stay written; a violation of a referenced object comes through the same way.
- Changing a value releases the previous one; `delete()` releases the values of the object.
- Copying and moving objects into the storage ([Copying and moving](transfer.md)) — a copy violating an index fails like any other object.
- The [viewer](maintenance.md#the-web-viewer) checks when editing and duplicating records.

Every process writing to the storage has to `attach()` its manager; a process that doesn't writes unchecked. `rebuild()` claims the values of all objects again afterwards and reports duplicates.

## Creating, rebuilding and dropping

```php
use melia\ObjectStorage\Unique\Exception\DuplicateValuesException;

try {
    $unique->create(new Definition('product-sku', Product::class, 'sku'));
} catch (DuplicateValuesException $e) {
    $e->getDuplicates(); // ['["s:CH-1"]' => [uuid, uuid], ...]: the objects sharing values
}

$unique->rebuild('product-sku'); // claims the values again, e.g. after writes without the index attached
$unique->drop('product-sku');    // the objects are not affected
$unique->list();                 // all indexes, name => Definition
$unique->getIndexesForClass(Product::class);
```

`create()` claims the values of the objects stored before. If some of them share values, the index isn't created — resolve the duplicates first, like with a database. `rebuild()` leaves the claims as they were in that case.

A long-running process sees indexes created or dropped by another process after `refresh()`.

### Per path, e.g. from a form

`PathIndexes` manages unique indexes on single paths, as a "unique" flag per field does: which paths of a class are unique, and making exactly the chosen ones unique. If creating one fails, the call is undone.

```php
use melia\ObjectStorage\Unique\PathIndexes;

$paths = new PathIndexes($unique);
$paths->forClass(Customer::class); // ['email' => Definition, ...], whatever their names
$paths->apply(Customer::class, [
    'email' => ['unique' => true, 'caseInsensitive' => true, 'name' => 'email'], // created as "customers-email"
    'name'  => ['unique' => false],                                              // dropped, if it was unique
], 'customers');
```

## Viewer and CLI

In the [viewer](maintenance.md#the-web-viewer), **Unique indexes** on the page of the classes lists, creates, rebuilds and drops them. When creating or editing a **search index**, each field has a **Unique** flag (with **ignore case**): checking it creates a unique index on the path of the field, unchecking it drops the unique index on that path — also one created elsewhere. Saving fails, and nothing is saved, if stored objects share a value.

The [CLI](cli.md) has `unique:list`, `unique:create`, `unique:rebuild`, `unique:drop` and `unique:find`, and checks the writes of all its commands (`put`, `copy`, `move`, …) against the unique indexes of the storage:

```bash
./object-storage/bin/object-storage unique:create customer-email --class 'App\Customer' --path email --case-insensitive --dir var/storage
./object-storage/bin/object-storage unique:create order-number --class 'App\Order' -p customer -p number --dir var/storage
./object-storage/bin/object-storage unique:find customer-email ada@example.com --dir var/storage   # the UUID, exit code 1 if none
```

## How it works

For each value (the key: the normalized values at the paths), a **claim** names the object that has it, stored under a UUID derived from the index and the key in the internal storage `<storage>/unique/internal`. While an object is written, the claims of its new values stay locked, so of several processes writing the same value at the same time exactly one succeeds. The claims use the same locks as the storage, see [Named configurations](configuration.md#named-configurations).

A claim whose object doesn't have the value anymore — left by a crash between writing the object and updating the claims, or by a write without the index attached — isn't trusted: `find()` ignores it, and the next object with the value takes it over.

## In comparison

| | Unique index | Search index | UUID derived from the value |
|---|---|---|---|
| prevents duplicates | yes, checked on every write | no | yes, for one value |
| finds by value | `find()`, one read | `search()`, with filters | `load()`, one read |
| value can change | yes | yes | no: the UUID is the value |
| combinations, case | yes | — | by building the input |

For a value that never changes, a UUID derived from it (`UUID\Helper::createFixedUuidV4('customer:' . $email)`) is the simplest unique key; for values that can change, use a unique index. See [Coming from Doctrine ORM](examples/coming-from-doctrine.md) for both in a shop.
