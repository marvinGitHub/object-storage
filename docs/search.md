# Search indexes

Search indexes make stored objects searchable by full text, and filterable and sortable by their fields. The storage stays the source of the data: an index holds a copy of selected fields of the objects of one class, and finds their UUIDs. A complete example is [Product search](examples/product-search.md).

Indexes are defined, built, kept up to date and searched the same way whatever **search engine** stores them. Only the engine itself and the language of its filters differ. The library comes with an engine for [Loupe](https://github.com/loupe-php/loupe), a search engine on SQLite that runs in PHP without a server; [other engines](#connecting-another-engine) can be connected.

## Requirements

The Loupe engine needs:

```bash
composer require loupe/loupe
```

and the PHP extensions `intl`, `pdo_sqlite` (SQLite 3.35 or newer) and `mbstring`.

Keep the storage directory on a **local file system**. SQLite in WAL mode needs shared memory, which doesn't work on network file systems, nor on Docker bind mounts of Windows or macOS folders: building or searching a Loupe index hangs there.

## The index manager

All indexes of a storage are managed by one `Manager`, with the engines it may use; the first one is the default:

```php
use melia\ObjectStorage\Search\Index\Manager;
use melia\ObjectStorage\Search\Loupe\LoupeEngine;

$search = new Manager($storage, [new LoupeEngine()]);
$search->attach(); // keeps all indexes of the storage up to date, see below
```

Create it where you create the storage, and share it, e.g. as a service. Code using it only needs the interfaces `ManagerInterface` and `IndexInterface`, which don't depend on the engine.

## Defining an index

An index covers the objects of exactly one class (not its subclasses) and the fields you list:

```php
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\Definition;

$search->create(new Definition(
    name: 'products',
    className: Product::class,
    fields: [
        new Field('name', 'name'),
        new Field('description', 'description'),
        new Field('price', 'price', FieldType::NUMBER, searchable: false, filterable: true, sortable: true),
        new Field('tags', 'tags', FieldType::STRING_ARRAY, filterable: true),
        new Field('city', 'address.city', searchable: false, filterable: true),
        new Field('category', 'category', searchable: false, filterable: true),
    ],
    languages: ['de', 'en'],
));
```

| Parameter | Meaning |
|---|---|
| `name` | name of the index, unique per storage, see [below](#using-an-index-by-its-name): lowercase letters, digits, `_` and `-` |
| `className` | class of the indexed objects |
| `fields` | the indexed fields, see below |
| `languages` | ISO 639-1 codes of the content for stemming (e.g. "apples" finds "apple"), for engines that support it; empty to detect them |
| `engine` | name of the engine, e.g. `loupe`; the default engine of the manager if omitted. It can't be changed later |

A field takes a value from the **stored data** of an object, not from the object itself: the index is built without loading objects, so it also works where the classes aren't available, e.g. in the viewer.

| Field parameter | Meaning |
|---|---|
| `name` | attribute name in the index, used in filters and sorting: letters, digits and `_`, starting with a letter |
| `path` | path of the value in the stored data, separated by dots: `address.city`. A path segment that isn't a key of a list applies to all elements: `lines.title` collects the titles of all lines |
| `type` | the type the value is converted to, see below |
| `searchable` | the full-text search covers the field (default) |
| `filterable` | the field can be used in filters and facets |
| `sortable` | results can be sorted by the field; not for lists |

Every document also has the attributes `uuid` (filterable) and `revision` (the [revision](concurrency.md#optimistic-locking-detecting-lost-updates-instead-of-waiting) of the object), so these names can't be used for fields.

What the path leads to:

- **Properties** as stored, see [Serialization](serialization.md); with `__serialize()`, the keys of the returned array.
- **Referenced objects** as their UUID: a field `category` holds the UUID of the category, so a filter like `category = '<uuid>'` finds all products of a category. A path doesn't lead into referenced objects (`category.title` is `null`); store the value in the object itself, or in an array, to index it.
- A missing value is `null`.

### Types

Engines usually expect one type per attribute; Loupe, for instance, derives it from the first document and rejects documents with another type. So every value is converted to the declared type, as long as no information is lost; otherwise the field is indexed as `null`, and the document is still indexed.

| Type | Converts | to `null` |
|---|---|---|
| `STRING` | strings; numbers and booleans as text | lists, objects |
| `NUMBER` | integers, floats, numeric strings (`"12.50"`) | other strings, booleans |
| `BOOLEAN` | booleans, `0`/`1`, `"0"`/`"1"`, `"true"`/`"false"` | everything else |
| `STRING_ARRAY` | lists, element by element as `STRING`; a single value as a list of one | |
| `NUMBER_ARRAY` | lists, element by element as `NUMBER` | |

Elements of a list that can't be converted are left out. This matters for data stored over time with changing types, e.g. a price stored as `"12.50"` by an older version of a class: it is still found by `price > 10`.

## Building

A new index is empty. Building it indexes all objects of the class:

```php
$index = $search->get('products');
$index->build();

// with progress
$index->build(static function (array $progress): void {
    printf("%d/%d (%d %%), %d failed\n", $progress['processed'], $progress['total'], $progress['percent'], $progress['failed']);
});
```

`build()` runs `startBuild()` and then `buildStep()` until the progress reports `done`. You can call these yourself to spread a build over several requests, as the viewer does: the build state is stored with the index, so any process can continue it.

| Progress key | Meaning |
|---|---|
| `status` | `new`, `building`, `ready` or `outdated` (the `STATUS_` constants of `IndexInterface`) |
| `total`, `processed`, `failed`, `percent` | counts of the running build |
| `done` | whether the build is complete |
| `errors` | the last 20 errors, e.g. documents the engine rejected |
| `documents` | documents in the index once done |

A build writes a new **generation** of the index. Searches keep using the previous generation until the build is complete, and objects stored or deleted while it runs are taken over at its end. `cancelBuild()` discards a running build.

### Changing an index

```php
$search->update(new Definition('products', Product::class, [/* changed fields */]));
$search->get('products')->build();
```

After `update()`, the index is `outdated`: searches use the previous definition until it is built again. A definition can't be changed while a build is running, and the engine of an index can't be changed: drop it and create it with the other engine.

`$search->drop('products')` removes an index with all its data; the stored objects aren't affected.

## Keeping indexes up to date

```php
$search->attach();
```

Once attached, every object of an indexed class that is stored or deleted through this storage is recorded as a **pending change** of its indexes and, with auto sync (the default), applied right after `store()` or `delete()` returns. This covers referenced objects written along, objects deleted along by the [child deletion policy](configuration.md#child-deletion-whether-referenced-objects-are-deleted-along) and class changes. Attach the manager in every process that writes, e.g. where you create the storage.

```php
$search->attach(autoSync: false);

// later, e.g. in a cron job or a queue worker
$search->sync();
```

Without auto sync, `store()` only records the UUID of the object, and `sync()` applies all recorded changes at once. An object changed several times before the sync is recorded, and indexed, once. With auto sync, a change that can't be applied right away, e.g. while another process is building the index, stays recorded for the next sync. `countPendingChanges()` tells how many there are, `hasPendingChange($uuid)` whether an object is waiting.

Applying a change reads the current state of the object, so the order of the changes doesn't matter, and applying one twice does no harm. An index error never makes `store()` or `delete()` fail; it is logged by the storage's logger.

Objects written without the storage, e.g. by editing their files, aren't recorded. Notify the manager instead:

```php
$search->notifyChanged($uuid);                                      // all indexes of the class of the object
$search->notifyChanged($uuid, Product::class, ['products']);        // only these; returns the updated ones
```

Indexes of other classes and unknown names in the list are ignored. An index that isn't notified keeps the previous state of the object until it is rebuilt.

## Using an index by its name

The name identifies an index: an application loads it by name, wherever it was created, in code or in the [viewer](#the-viewer), and whatever its engine:

```php
use melia\ObjectStorage\Search\Exception\IndexNotFoundException;

$products = $search->get('products'); // IndexNotFoundException if there is no such index

$search->has('products');             // whether it exists
$search->list();                      // all indexes, name => index
```

Names are **unique** per storage, across all engines. Creating an index with a name that exists throws an `IndexAlreadyExistsException`, also if several processes create it at the same time: the name is checked and the index created under a lock, so exactly one of them succeeds. Names consist of lowercase letters, digits, `_` and `-`, so they are unique on case-insensitive file systems too.

A service of an application might look like this:

```php
use melia\ObjectStorage\Search\Index\ManagerInterface;
use melia\ObjectStorage\Search\Request;

final class ProductSearch
{
    public function __construct(private readonly ManagerInterface $search) {}

    /** @return iterable<string, Product> */
    public function find(string $text, ?float $maxPrice = null): iterable
    {
        return $this->search->get('products')->searchObjects(new Request(
            query: $text,
            filter: null === $maxPrice ? null : sprintf('price <= %F', $maxPrice),
        ));
    }
}
```

Quote values from user input in filters, or check them: a filter is a string, like an SQL condition. Numbers formatted with `%F` and UUIDs are safe; for strings, see the language of the engine (Loupe: single quotes, with `'` doubled as in SQL: `sprintf("name = '%s'", str_replace("'", "''", $name))`).

## Searching

`search()` takes a `Request` and returns a `Result`, independent of the engine:

```php
use melia\ObjectStorage\Search\Request;

$result = $search->get('products')->search(new Request(
    query: 'crunchy apple',
    filter: "price < 10 AND tags IN ('organic', 'regional')", // in the language of the engine
    sort: ['price:asc'],
    facets: ['tags'],
    page: 1,
    hitsPerPage: 20,
    highlight: true,
));

$result->totalHits;
$result->totalPages;
$result->facets;             // ['tags' => ['organic' => 12, 'regional' => 3]]
foreach ($result->hits as $hit) {
    $hit->uuid;
    $hit->document['name'];  // the indexed fields
    $hit->highlighted;       // searchable fields with matches, e.g. ['description' => 'A <mark>crunchy</mark> …']
    $hit->score;             // relevance of full-text queries, if the engine provides it
}
```

| Request parameter | Meaning |
|---|---|
| `query` | full-text query over the searchable fields; empty for all documents |
| `filter` | a condition over the filterable fields, **in the language of the engine** |
| `sort` | `['price:asc', 'name:desc']`, sortable fields; empty for relevance |
| `facets` | filterable fields to count the values of among the hits |
| `page`, `hitsPerPage` | paging, starting with page 1 |
| `highlight`, `highlightStartTag`, `highlightEndTag` | return the matches marked (by default with `<mark>`); the text isn't escaped |

`searchObjects()` takes the same request and loads the found objects, in the order of the hits, as `UUID => object`. Objects deleted or expired since they were indexed are skipped:

```php
foreach ($search->get('products')->searchObjects(new Request('apple')) as $uuid => $product) {
    // ...
}
```

Searching an index that has never been built throws an `IndexNotReadyException`.

### Features of an engine

For features beyond `Request`, pass a query object of the engine instead; `$result->raw` holds the result of the engine. An engine rejects query objects it doesn't know with an `UnsupportedQueryException`.

## The Loupe engine

The filter language of Loupe ([documentation](https://github.com/loupe-php/loupe/blob/main/docs/searching.md)):

| Filter | Example |
|---|---|
| comparisons | `price >= 10`, `name != 'x'`, `available = true` |
| ranges | `price BETWEEN 10 AND 20` |
| lists | `tags IN ('a', 'b')`, `tags NOT IN ('a')` |
| empty values | `city IS NULL`, `city IS NOT NULL`, `tags IS EMPTY`, `tags IS NOT EMPTY` |
| combinations | `AND`, `OR`, `NOT` and parentheses |

Strings go in single quotes, with `'` doubled. Full-text queries are typo-tolerant, `"exact phrase"` searches a phrase, `-word` excludes a word, and words of three or more letters also match as prefixes. `_relevance:desc` sorts by relevance explicitly.

Loupe's own `SearchParameters` are accepted as well, e.g. to return only some attributes:

```php
use Loupe\Loupe\SearchParameters;

$result = $index->search(SearchParameters::create()->withQuery('apple')->withAttributesToRetrieve(['uuid', 'price']));
$result->raw; // Loupe's Result
```

The configuration of an index can be adjusted beyond its definition, e.g. typo tolerance or stop words:

```php
use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Configuration;

new LoupeEngine(configure: static fn(Configuration $configuration, Definition $definition): Configuration
    => $configuration->withTypoTolerance(TypoTolerance::disabled()));
```

## Connecting another engine

An engine implements two interfaces of `melia\ObjectStorage\Search\Engine`; everything else (definitions, building in steps, generations, pending changes, locking, unique names, the viewer) is done by the index manager:

```php
use melia\ObjectStorage\Search\Engine\EngineIndexInterface;
use melia\ObjectStorage\Search\Engine\EngineInterface;

final class MyEngine implements EngineInterface
{
    public function getName(): string { return 'my'; }

    // reject definitions the engine can't handle, e.g. field names
    public function validate(Definition $definition): void {}

    // the documents of a generation; $directory is unique per index and generation
    public function open(string $directory, Definition $definition): EngineIndexInterface { /* … */ }

    // remove the data of a generation kept outside the directory, e.g. on a server
    public function remove(string $directory, Definition $definition): void {}
}

final class MyEngineIndex implements EngineIndexInterface
{
    public function upsert(array $documents): array { /* add or replace; return UUID => error for rejected ones */ }
    public function delete(array $uuids): void { /* … */ }
    public function count(): int { /* … */ }
    public function search(object $request): Result { /* translate a Request, or accept own query objects */ }
    public function close(): void {}
}

$search = new Manager($storage, [new LoupeEngine(), new MyEngine()]);
$search->create(new Definition('articles', Article::class, $fields, engine: 'my'));
```

Documents arrive with the attributes `uuid`, `revision` and one per field, with values already converted to the types of the fields. The data of an index lives in `<storage dir>/indexes/<engine>/<name>/`; an engine storing its data elsewhere derives a unique name from the directory it gets. The viewer shows the syntax help of an engine from the template `views/search-help-<engine>.html`, if there is one.

## The viewer

The [viewer](maintenance.md#the-web-viewer) manages the indexes of a storage under **Search indexes**:

- **New index** / **Edit**: choose the class and the engine; **Suggest fields** fills in the fields from a stored object of the class, with types and options guessed from the values. Saving builds the index.
- **Rebuild** builds the index again, **Sync** applies the pending changes, **Drop** removes it.
- The build page shows the progress (indexed objects, failed ones, elapsed and remaining time) while it indexes the objects step by step; it can be left and continued.
- **Search** runs a query with filter, sort and facets, showing the matches highlighted, the ranking score and links to the objects.

Duplicates and deletes made in the viewer are applied to all indexes of the class. When **editing** a record, the viewer lists the indexes of its class (with engine, status and whether a change of the record is pending) and updates the chosen ones, all by default; the page after saving names the updated indexes and those left with the previous state.

## UUID tries

The sets of UUIDs of an index are kept in `melia\ObjectStorage\Util\UuidTrie`: a set of UUIDs as an object, stored like any other object as **one record**. It can also be used on its own:

```php
use melia\ObjectStorage\Util\UuidTrie;

$trie = new UuidTrie($uuidA, $uuidB);

$trie->add($uuidC);               // returns the number of added UUIDs; contained ones are ignored
$trie->contains($uuidA);          // true
$trie->remove($uuidB);

foreach ($trie as $uuid) {}       // all UUIDs, in ascending order
$trie->first(100);                // the first 100
$trie->first(100, after: $last);  // the next 100, e.g. in the next request
count($trie);

$uuid = $storage->store($trie);   // one record, however many UUIDs
$trie = $storage->load($uuid);
```

It is a radix trie over the 32 hex digits of the UUIDs: a node maps runs of digits to child nodes, and chains without branches are merged into one edge, so the trie has at most about two nodes per UUID. In the stored data, it is readable as such:

```json
{"count": 3, "root": {"k7d3f": {"k4b5e2a1c4e8f9b6d0c1a2b3c4d5": {"ke": true, "kf": true}, "k0000000040008000000000000000": true}}}
```

Edges are prefixed with `k`, as PHP would turn numeric keys into integers; `true` marks a complete UUID. Looking up a UUID follows its digits, and iterating can start after any UUID, which lets a build continue where its previous step stopped.

As one record, a set is read and written as a whole, like any object: changing it means loading it, changing it and storing it again, under the lock of the storage. As a guide, a set of 100,000 UUIDs takes 4 MB as a record and 25 MB in memory, loads in about 50 ms, and adding a UUID and storing it again takes about 70 ms; 10,000 UUIDs take a tenth of that. This suits the sets of an index: the UUIDs of a build are written once, and pending changes are usually few, as auto sync applies them right away. Without auto sync, the pending changes grow until the next sync, and every recorded change writes them all.

## Things to know

- **Files**: everything lives in `<storage dir>/indexes/`. The definition, the state (status, build progress, last errors) and three sets of UUIDs of every index are objects of an internal object storage in `internal/`, written crash-safe like any stored object, one record each. The sets are [UUID tries](#uuid-tries): the pending changes, the objects still to index by a running build, and the changes to take over at its end. The directory of an index, `<engine>/<name>/`, only holds the data of the engine for the active generation (`data-<generation>/`, with the definition it was built with). Back the directory up with the storage, or rebuild the indexes.
- **Eventual consistency**: with auto sync, an index is up to date when `store()` returns, unless another process holds the index; then it is up to date after the next sync. The storage is the source: `searchObjects()` loads the current state of each hit.
- **Several processes** may store, sync and search at the same time. Syncs and build steps of an index run one after the other, under a lock of the internal storage; searches and `store()` never wait for them.
- **Speed** with Loupe: as a rough guide, building an index of 2,000 small products took 1 to 9 seconds on a laptop, and searches with filters, sorting and facets took 3 to 13 milliseconds. Measure with your data: long texts, many fields and many languages cost more.
- **Renamed classes**: an index refers to the class name as stored. After [renaming a class](serialization.md#renamed-and-removed-classes) and migrating the data, update the definition and rebuild.
