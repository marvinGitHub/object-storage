<?php

/**
 * The shop of docs/examples/coming-from-doctrine.md: entities, a strategy and repositories, as an application
 * would write them; tested by ComingFromDoctrineTest, so the documented code keeps working.
 */

namespace Tests\melia\ObjectStorage\Examples\Shop;

use melia\ObjectStorage\LazyLoadReference;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\Manager;
use melia\ObjectStorage\Search\Request;
use melia\ObjectStorage\Strategy\Policy\ChildDeletion;
use melia\ObjectStorage\Strategy\Standard;
use melia\ObjectStorage\Unique\Definition as UniqueDefinition;
use melia\ObjectStorage\Unique\Exception\UniqueConstraintViolationException;
use melia\ObjectStorage\Unique\Manager as UniqueManager;
use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\AwareTrait;

// ---------------------------------------------------------------------------------------------------------------
// Entities: plain classes. No mapping, no id column: AwareTrait gives them their UUID.
// ---------------------------------------------------------------------------------------------------------------

final class Customer implements AwareInterface
{
    use AwareTrait;

    public function __construct(
        public string $email,
        public string $name,
    ) {}
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
        /** @var string[] */
        public array  $tags = [],
    ) {}
}

final class OrderLine
{
    public function __construct(
        // ManyToOne: a reference, loaded lazily thanks to the union type
        public LazyLoadReference|Product $product,
        public int                       $quantity,
        public float                     $price, // the price at the time of the order
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

    /**
     * OneToMany: an array of objects, each stored as an object of its own. Like a Doctrine collection, the
     * elements are loaded lazily: after loading they are LazyLoadReferences until used, see lines().
     *
     * @var array<OrderLine|LazyLoadReference>
     */
    public array $lines = [];
    public string $status = self::STATUS_NEW;
    public float $total = 0.0;
    public float $placedAt;

    public function __construct(
        public LazyLoadReference|Customer $customer,
    ) {
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

    /**
     * @return OrderLine[] The lines, loaded.
     */
    public function lines(): array
    {
        return array_map(static fn(OrderLine|LazyLoadReference $line): OrderLine => $line instanceof LazyLoadReference ? $line->getObject() : $line, $this->lines);
    }
}

// ---------------------------------------------------------------------------------------------------------------
// Cascades: what Doctrine's cascade={"remove"} / orphanRemoval do, as a strategy
// ---------------------------------------------------------------------------------------------------------------

final class ShopStrategy extends Standard
{
    public function __construct()
    {
        $this->setPolicyChildDeletion(ChildDeletion::CALLBACK);
        $this->enableOptimisticLocking(); // like #[ORM\Version]
    }

    // deleting an order deletes its lines, but not its customer or the products
    public function shouldDeleteChild(string $parentUuid, string $parentClassName, string $childUuid, string $childClassName, array $path): bool
    {
        return Order::class === $parentClassName && OrderLine::class === $childClassName;
    }
}

// ---------------------------------------------------------------------------------------------------------------
// Indexes: what the database indexes (and a full-text engine) did, created once
// ---------------------------------------------------------------------------------------------------------------

final class ShopIndexes
{
    public static function install(Manager $search, UniqueManager $unique): void
    {
        // UNIQUE constraints
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

// ---------------------------------------------------------------------------------------------------------------
// Repositories: the same role as Doctrine's, on top of the storage and the search
// ---------------------------------------------------------------------------------------------------------------

final class CustomerRepository
{
    public function __construct(
        private readonly ObjectStorage $storage,
        private readonly UniqueManager $unique,
    ) {}

    public function find(string $uuid): ?Customer
    {
        $customer = $this->storage->load($uuid);
        return $customer instanceof Customer ? $customer : null;
    }

    /** a lookup in the unique index, like findOneBy(['email' => …]) on a unique column */
    public function findByEmail(string $email): ?Customer
    {
        $customer = $this->unique->find('customer-email', $email);
        return $customer instanceof Customer ? $customer : null;
    }

    /** creates the customer once, also when several requests register the same email at the same time */
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

    /**
     * Full text with filters, like a query on a FULLTEXT index or Elasticsearch.
     *
     * @return array<string, Product>
     */
    public function search(string $text, ?float $maxPrice = null, bool $inStockOnly = false): array
    {
        $filters = [];
        if (null !== $maxPrice) {
            $filters[] = sprintf('price <= %F', $maxPrice);
        }
        if ($inStockOnly) {
            $filters[] = 'stock > 0';
        }

        // the index is chosen per search, see Manager::explain()
        return iterator_to_array($this->search->searchObjects(Product::class, new Request(
            query: $text,
            filter: [] === $filters ? null : implode(' AND ', $filters),
        )));
    }

    /** a lookup in the unique index of the SKU, no search needed */
    public function findOneBySku(string $sku): ?Product
    {
        $product = $this->unique->find('product-sku', $sku);
        return $product instanceof Product ? $product : null;
    }
}

final class OrderRepository
{
    public function __construct(
        private readonly ObjectStorage $storage,
        private readonly Manager       $search,
    ) {}

    public function save(Order $order): string
    {
        return $this->storage->store($order); // the lines are stored along, the customer and products are referenced
    }

    public function remove(Order $order): void
    {
        $this->storage->delete($order->getUUID()); // the lines go along, see ShopStrategy
    }

    /**
     * The orders of a customer, newest first, page by page: a findBy() with orderBy, limit and offset.
     *
     * @return array{orders: array<string, Order>, total: int}
     */
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

    /**
     * The number of orders per status: a GROUP BY with COUNT(), from the facets of the index.
     *
     * @return array<string, int> status => number of orders
     */
    public function countByStatus(): array
    {
        return $this->search->search(Order::class, new Request(facets: ['status'], hitsPerPage: 0))->facets['status'] ?? [];
    }
}
