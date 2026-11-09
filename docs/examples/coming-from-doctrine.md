# Coming from Doctrine ORM

A small shop — customers, products, orders with lines — built the way you would with Doctrine ORM, and then with the object storage instead: entities, repositories, associations, cascades, unique keys, queries, full-text search, optimistic locking and transactions.

The code below is tested: [`tests/…/Examples/Shop/Shop.php`](../../tests/melia/ObjectStorage/Examples/Shop/Shop.php) holds the classes, [`ComingFromDoctrineTest`](../../tests/melia/ObjectStorage/Examples/ComingFromDoctrineTest.php) runs every step shown here. The search needs `composer require loupe/loupe` and the PHP extensions `intl` and `pdo_sqlite`.

## At a glance

| Doctrine ORM | Object storage |
|---|---|
| `#[ORM\Entity]`, mapping, schema, migrations | plain classes; whatever their properties hold is stored ([Serialization](../serialization.md)) |
| `#[ORM\Id] #[ORM\GeneratedValue]` | a UUID, assigned by `store()`; `AwareInterface` + `AwareTrait` put it into the object |
| `EntityManager::persist()` + `flush()` | `$storage->store($object)`, written right away |
| `EntityManager::find($id)` | `$storage->load($uuid)` |
| `EntityManager::remove()` + `flush()` | `$storage->delete($uuid)` |
| `ManyToOne`, `OneToOne` | a property holding the object; stored as a reference |
| `OneToMany`, `ManyToMany` collections | an array of objects |
| lazy proxies, `fetch: 'LAZY'` | `LazyLoadReference` in properties typed e.g. `LazyLoadReference\|Customer`, and in arrays |
| `cascade: ['persist']` | the default: new referenced objects are stored along ([child persistence](../configuration.md#child-persistence-when-referenced-objects-are-written)) |
| `cascade: ['remove']`, `orphanRemoval` | the [child deletion policy](../configuration.md#child-deletion-whether-referenced-objects-are-deleted-along) of the strategy |
| `#[ORM\UniqueConstraint]`, `unique: true` | a [unique index](../unique.md) |
| `findBy()`, QueryBuilder, DQL | a [search index](../search.md): filters, sorting, paging, facets; `match()` for small sets |
| `JOIN` in DQL, e.g. orders by the name of their customer | a field with a path into the referenced object, `customer.name` ([joins](../search.md#joins-paths-into-referenced-objects)) |
| a FULLTEXT index, Elasticsearch | the same search index: full text, typo tolerant |
| `#[ORM\Version]` | [optimistic locking](../concurrency.md#optimistic-locking-detecting-lost-updates-instead-of-waiting) |
| `wrapInTransaction()` | `Transaction` |
| lifecycle callbacks, event subscribers | [events](../events.md) |

## Entities

With Doctrine, an order line is an entity with an id, a foreign key and a mapping:

```php
#[ORM\Entity]
class OrderLine
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    private Order $order;

    #[ORM\ManyToOne]
    private Product $product;

    #[ORM\Column]
    private int $quantity;
}
```

With the object storage, the classes are plain PHP. Objects that need to know their UUID — like entities their id — implement `AwareInterface`:

```php
use melia\ObjectStorage\LazyLoadReference;
use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\AwareTrait;

final class Customer implements AwareInterface
{
    use AwareTrait;

    public function __construct(public string $email, public string $name) {}
}

final class Product implements AwareInterface
{
    use AwareTrait;

    public function __construct(
        public string $sku,
        public string $name,
        public string $description,
        public float  $price,
        public int    $stock = 0,
        public array  $tags = [],
    ) {}
}

final class OrderLine
{
    public function __construct(
        public LazyLoadReference|Product $product, // ManyToOne, loaded lazily
        public int                       $quantity,
        public float                     $price,
    ) {}

    public function product(): Product
    {
        return $this->product instanceof LazyLoadReference ? $this->product->getObject() : $this->product;
    }
}

final class Order implements AwareInterface
{
    use AwareTrait;

    public const string STATUS_NEW = 'new';
    public const string STATUS_SHIPPED = 'shipped';

    /** @var array<OrderLine|LazyLoadReference> OneToMany */
    public array $lines = [];
    public string $status = self::STATUS_NEW;
    public float $total = 0.0;
    public float $placedAt;

    public function __construct(public LazyLoadReference|Customer $customer) // ManyToOne
    {
        $this->placedAt = microtime(true);
    }

    public function add(Product $product, int $quantity): void
    {
        $this->lines[] = new OrderLine($product, $quantity, $product->price);
        $this->total = round($this->total + $quantity * $product->price, 2);
    }

    public function customer(): Customer
    {
        return $this->customer instanceof LazyLoadReference ? $this->customer->getObject() : $this->customer;
    }

    /** @return OrderLine[] */
    public function lines(): array
    {
        return array_map(static fn($line) => $line instanceof LazyLoadReference ? $line->getObject() : $line, $this->lines);
    }
}
```

What is different:

- **No inverse side.** `OrderLine` doesn't need `$order`; the order holds its lines. Bidirectional associations work as well (circular references are stored once), but are rarely needed.
- **Every object is a record of its own**, also an `OrderLine`: it has a UUID, even without `AwareInterface`. Values that shouldn't be records of their own go into arrays (like Doctrine's embeddables).
- **Lazy loading is visible.** Doctrine's proxies extend the entity class; a `LazyLoadReference` doesn't, so the property type has to allow it (`LazyLoadReference|Customer`), and code that needs the object calls `getObject()` — here in `customer()` and `lines()`. A property typed `Customer` only is loaded right away instead. Elements of arrays are always lazy, like a collection that isn't initialized yet. See [Lazy loading](../concepts.md#lazy-loading).
- **Prices as stored values.** The line copies the price; when the product's price changes later, the order keeps its own, as with a column.

## The "entity manager"

```php
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Search\Index\Manager;
use melia\ObjectStorage\Search\Loupe\LoupeEngine;
use melia\ObjectStorage\Unique\Manager as UniqueManager;

$shop = new ObjectStorage(__DIR__ . '/var/shop', strategy: new ShopStrategy());

$unique = new UniqueManager($shop);                // the unique indexes of the storage
$search = new Manager($shop, [new LoupeEngine()]); // the search indexes of the storage
ShopIndexes::install($search, $unique);            // once, see below
$unique->attach();                                 // checks every store(): no duplicate emails or SKUs
$search->attach();                                 // keeps the search indexes up to date with every store() and delete()
```

There is no unit of work and no `flush()`: `store()` writes the object and the new objects it references when it is called, and returns the UUID. Storing an unchanged object writes nothing.

## Cascades and orphan removal

Doctrine's `cascade: ['persist']` is the default here: storing an order stores its new lines, while the customer and the products, stored before, are only referenced. Deleting is explicit: by default, `delete()` removes the object only. The strategy decides what goes along — here what `cascade: ['remove']` and `orphanRemoval` would do for `Order::$lines`:

```php
use melia\ObjectStorage\Strategy\Policy\ChildDeletion;
use melia\ObjectStorage\Strategy\Standard;

final class ShopStrategy extends Standard
{
    public function __construct()
    {
        $this->setPolicyChildDeletion(ChildDeletion::CALLBACK);
        $this->enableOptimisticLocking(); // like #[ORM\Version], see below
    }

    // deleting an order deletes its lines, but not its customer or the products
    public function shouldDeleteChild(string $parentUuid, string $parentClassName, string $childUuid, string $childClassName, array $path): bool
    {
        return Order::class === $parentClassName && OrderLine::class === $childClassName;
    }
}
```

A line removed from `$order->lines` stays stored, though no longer referenced; delete it yourself when you remove it, if that matters.

## Indexes: the queries of the shop

Doctrine's repositories query the database, with its indexes. Here, two kinds of indexes take their place:

- A [unique index](../unique.md) is a `UNIQUE` constraint: no two customers with the same email, no two products with the same SKU. It also finds an object by its value.
- A [search index](../search.md) per class answers the queries: it covers the fields you list, with full text, filters, sorting, paging and facets.

Define them once, e.g. in a setup script or a deployment step, like a migration:

```php
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\Manager;
use melia\ObjectStorage\Unique\Definition as UniqueDefinition;
use melia\ObjectStorage\Unique\Manager as UniqueManager;

final class ShopIndexes
{
    public static function install(Manager $search, UniqueManager $unique): void
    {
        // UNIQUE constraints; creating one fails if objects stored before share values
        if (!$unique->has('customer-email')) {
            $unique->create(new UniqueDefinition('customer-email', Customer::class, 'email', caseInsensitive: true));
        }
        if (!$unique->has('product-sku')) {
            $unique->create(new UniqueDefinition('product-sku', Product::class, 'sku'));
        }

        // search indexes: queries and full text
        if (!$search->has('products')) {
            $search->create(new Definition('products', Product::class, [
                new Field('sku', 'sku', searchable: false, filterable: true),
                new Field('name', 'name'),
                new Field('description', 'description'),
                new Field('price', 'price', FieldType::NUMBER, searchable: false, filterable: true, sortable: true),
                new Field('stock', 'stock', FieldType::NUMBER, searchable: false, filterable: true),
                new Field('tags', 'tags', FieldType::STRING_ARRAY, filterable: true),
            ], languages: ['en']));
        }

        if (!$search->has('orders')) {
            $search->create(new Definition('orders', Order::class, [
                new Field('customer', 'customer', searchable: false, filterable: true), // the UUID of the customer
                new Field('customerName', 'customer.name'),                             // a join: the name of the customer
                new Field('status', 'status', searchable: false, filterable: true),
                new Field('total', 'total', FieldType::NUMBER, searchable: false, filterable: true, sortable: true),
                new Field('placedAt', 'placedAt', FieldType::NUMBER, searchable: false, filterable: true, sortable: true),
            ]));
        }

        // objects stored before the index existed are indexed once; later changes follow by attach()
        foreach ($search->list() as $index) {
            if (!$index->isSearchable()) {
                $index->build();
            }
        }
    }
}
```

- A field takes its value from the stored data: `customer` holds the UUID of the referenced customer — the foreign key, so to speak.
- A path leads into referenced objects, like a JOIN: `customer.name` indexes the name of the customer with the order, so `$search->searchObjects(Order::class, new Request('Ada'))` finds the orders of Ada. Renaming the customer updates their orders, see [Joins](../search.md#joins-paths-into-referenced-objects).
- With `attach()`, every `store()` and `delete()` updates the indexes of the class before it returns. For many writes, `attach(autoSync: false)` only records the change, and a cron job calls `$search->sync()`.
- Adding a field later: `$search->update()` with the new definition, then `build()`; searches use the previous build meanwhile. The [viewer](../maintenance.md#the-web-viewer) does the same under **Search indexes**, with progress.

## Repositories

The repositories keep the roles they have with Doctrine.

### find() and a unique email

The unique index `customer-email` keeps the email unique and finds a customer by it. A duplicate fails with a `UniqueConstraintViolationException`, which names the customer having the email — like Doctrine's exception of the same name:

```php
use melia\ObjectStorage\Unique\Exception\UniqueConstraintViolationException;

final class CustomerRepository
{
    public function __construct(private readonly ObjectStorage $storage, private readonly UniqueManager $unique) {}

    public function find(string $uuid): ?Customer
    {
        $customer = $this->storage->load($uuid);
        return $customer instanceof Customer ? $customer : null;
    }

    // a lookup in the unique index, like findOneBy(['email' => …]) on a unique column
    public function findByEmail(string $email): ?Customer
    {
        $customer = $this->unique->find('customer-email', $email);
        return $customer instanceof Customer ? $customer : null;
    }

    // creates the customer once, also when several requests register the same email at the same time
    public function register(string $email, string $name): Customer
    {
        try {
            $customer = new Customer($email, $name);
            $this->storage->store($customer);
            return $customer;
        } catch (UniqueConstraintViolationException $e) {
            return $this->find($e->getOwner()); // registered before (or by another request just now)
        }
    }
}
```

The email is compared without case and surrounding spaces (`caseInsensitive`), and a customer may change it: the old email is released when the new one is stored.

For a value that never changes, there is a simpler way without an index: derive the UUID from it, `UUID\Helper::createFixedUuidV4('customer:' . strtolower(trim($email)))`, and store the customer under it — the same email always gives the same UUID, and finding is a `load()`. See [Unique indexes](../unique.md#in-comparison).

### Full-text search and findOneBy()

```php
use melia\ObjectStorage\Search\Request;

final class ProductRepository
{
    public function __construct(
        private readonly ObjectStorage $storage,
        private readonly Manager       $search,
        private readonly UniqueManager $unique,
    ) {}

    public function save(Product $product): string
    {
        return $this->storage->store($product);
    }

    /** @return array<string, Product> */
    public function search(string $text, ?float $maxPrice = null, bool $inStockOnly = false): array
    {
        $filters = [];
        if (null !== $maxPrice) {
            $filters[] = sprintf('price <= %F', $maxPrice);
        }
        if ($inStockOnly) {
            $filters[] = 'stock > 0';
        }

        // the manager chooses the index of the class that can answer the request
        return iterator_to_array($this->search->searchObjects(Product::class, new Request(
            query: $text,
            filter: [] === $filters ? null : implode(' AND ', $filters),
        )));
    }

    // a lookup in the unique index of the SKU, no search needed
    public function findOneBySku(string $sku): ?Product
    {
        $product = $this->unique->find('product-sku', $sku);
        return $product instanceof Product ? $product : null;
    }
}
```

- `searchObjects()` returns the loaded objects in the order of the hits — by relevance for a full-text query —, as `UUID => object`; `search()` returns the hits with the indexed fields, without loading objects.
- The query is typo tolerant and covers all searchable fields: `lamp warm` finds the lamp by its name and description.
- Filters are written in the language of the engine (here [Loupe's](../search.md#the-loupe-engine)), like a `WHERE` clause. Quote strings from user input, as above.

### findBy() with orderBy, limit and offset

The orders of a customer, newest first, page by page:

```php
final class OrderRepository
{
    public function __construct(private readonly ObjectStorage $storage, private readonly Manager $search) {}

    public function save(Order $order): string
    {
        return $this->storage->store($order);
    }

    public function remove(Order $order): void
    {
        $this->storage->delete($order->getUUID()); // the lines go along, see ShopStrategy
    }

    /** @return array{orders: array<string, Order>, total: int} */
    public function findByCustomer(Customer $customer, int $page = 1, int $perPage = 20, ?string $status = null): array
    {
        $filter = sprintf("customer = '%s'", $customer->getUUID());
        if (null !== $status) {
            $filter .= sprintf(" AND status = '%s'", $status);
        }
        $request = new Request(filter: $filter, sort: ['placedAt:desc'], page: $page, hitsPerPage: $perPage);

        return [
            'orders' => iterator_to_array($this->search->searchObjects(Order::class, $request)),
            'total' => $this->search->search(Order::class, new Request(filter: $filter, hitsPerPage: 0))->totalHits,
        ];
    }

    /** @return array<string, int> status => number of orders, like GROUP BY status with COUNT(*) */
    public function countByStatus(): array
    {
        return $this->search->search(Order::class, new Request(facets: ['status'], hitsPerPage: 0))->facets['status'] ?? [];
    }
}
```

Which index answers a search, and why, shows `explain()` — like `EXPLAIN` of a database:

```php
echo $search->explain(Order::class, new Request(filter: "status = 'new'"))->describe();
// Search indexes of Order:
// * orders (loupe, ready): usable, rank 1
```

## Using it

```php
$customers = new CustomerRepository($shop, $unique);
$products = new ProductRepository($shop, $search, $unique);
$orders = new OrderRepository($shop, $search);

$ada = $customers->register('ada@example.com', 'Ada');
$customers->register('ADA@example.com', 'Ada');  // the same customer: the email exists
$chair = new Product('CH-1', 'Oak chair', 'A sturdy chair of solid oak', 89.0, stock: 4, tags: ['furniture', 'oak']);
$lamp = new Product('LA-1', 'Desk lamp', 'A small lamp with a warm light', 29.9, stock: 12, tags: ['light']);
$products->save($chair);
$products->save($lamp);
$products->findOneBySku('CH-1');               // the chair
// $products->save(new Product('CH-1', …));    // UniqueConstraintViolationException

$order = new Order($ada);
$order->add($chair, 2);
$order->add($lamp, 1);
$orders->save($order);                         // the lines are stored along
echo $order->getUUID();                        // like the generated id after flush()

$products->search('oak', maxPrice: 100);       // [chair UUID => Product]
$orders->findByCustomer($ada, page: 1);        // ['orders' => [...], 'total' => 1]

$loaded = $shop->load($order->getUUID());      // in another request
$loaded->customer;                             // a LazyLoadReference, nothing loaded yet
$loaded->customer()->name;                     // 'Ada', loaded now
foreach ($loaded->lines() as $line) {
    echo $line->product()->name, ' × ', $line->quantity, PHP_EOL;
}

$orders->remove($order);                       // and its lines; customer and products stay
```

## Optimistic locking and transactions

`#[ORM\Version]` becomes optimistic locking, enabled in `ShopStrategy` above: `store()` fails if someone else stored the object since it was loaded.

```php
use melia\ObjectStorage\Exception\RevisionConflictException;

$chair = $shop->load($uuid);
$chair->stock -= 2;

try {
    $shop->store($chair);
} catch (RevisionConflictException) {
    // like Doctrine's OptimisticLockException: load again and repeat, or report the conflict
}
```

For a short read-modify-write, an exclusive load is often simpler: it holds the lock until the object is stored (`$shop->load($uuid, exclusive: true)`), like `LockMode::PESSIMISTIC_WRITE`. See [Concurrency](../concurrency.md).

Several changes that must succeed together — `wrapInTransaction()` — go into a `Transaction`; on failure, the objects written so far are restored:

```php
use melia\ObjectStorage\Transaction;

$order = new Order($ada);
$order->add($chair, 2);
$chair->stock -= 2;

$transaction = (new Transaction($shop))->begin();
$transaction->store($order);
$transaction->store($chair, $chair->getUUID());
$transaction->commit(); // or rollback()
```

The objects are locked from their first change until the commit, and a commit interrupted by a crash is rolled back when the storage is opened next. Objects written along by the child persistence policy (here the customer, if it changed) aren't rolled back; see [Transactions](../transactions.md).

## Lifecycle callbacks and event subscribers

`prePersist`, `postUpdate` and the like become listeners on the [events](../events.md) of the storage, e.g. `BEFORE_STORE`, `OBJECT_SAVED` (with the previous and the new state) or `AFTER_DELETE`. The search indexes use them as well. See [An audit log from events](audit-log-with-events.md).

## Migrations

There is no schema to migrate: a new property is stored with the next `store()`. What needs care:

- **New properties of objects stored before** get the default value of their declaration when loaded (`public int $stock = 0;`). The default of a promoted constructor parameter doesn't apply, since loading doesn't call the constructor: such a property stays uninitialized. Declare the default on the property, or set it in `__unserialize()`.
- **Renamed classes** are mapped to their new names, see [Renamed and removed classes](../serialization.md#renamed-and-removed-classes) and [Renaming classes](renaming-classes.md).
- **Changed types** are converted on load where nothing is lost, see [Type conversion on load](../serialization.md#type-conversion-on-load); search indexes convert values to their declared types as well.
- **New fields in an index** need a rebuild, see above.

## When Doctrine remains the better choice

- **Ad-hoc queries over everything**: joins across many entities, arbitrary aggregates (`SUM`, `AVG`, `GROUP BY` over several columns), reporting. A search index answers the queries you designed it for; facets count values, but sums are up to you.
- **Relational integrity enforced by the database**: foreign keys (a referenced object can be deleted while others still reference it), check constraints, and constraints that hold for every writer — unique indexes are checked only by processes that attach them.
- **Many writers on one server** with large data and transactions over many objects.
- **Existing tooling** around SQL: BI tools, replication, backups at the database level.

The object storage fits when objects are read and written as a whole — documents, aggregates, configurations, caches —, the queries are known, and no database server is wanted. Both work side by side, too: e.g. orders in Doctrine, product documents and their search in the object storage.
