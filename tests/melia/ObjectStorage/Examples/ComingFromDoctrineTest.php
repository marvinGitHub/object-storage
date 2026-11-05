<?php

namespace Tests\melia\ObjectStorage\Examples;

use melia\ObjectStorage\Exception\RevisionConflictException;
use melia\ObjectStorage\LazyLoadReference;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Search\Index\Manager;
use melia\ObjectStorage\Search\Loupe\LoupeEngine;
use melia\ObjectStorage\Search\Request;
use melia\ObjectStorage\Transaction;
use melia\ObjectStorage\Unique\Exception\UniqueConstraintViolationException;
use melia\ObjectStorage\Unique\Manager as UniqueManager;
use Tests\melia\ObjectStorage\Examples\Shop\Customer;
use Tests\melia\ObjectStorage\Examples\Shop\CustomerRepository;
use Tests\melia\ObjectStorage\Examples\Shop\Order;
use Tests\melia\ObjectStorage\Examples\Shop\OrderLine;
use Tests\melia\ObjectStorage\Examples\Shop\OrderRepository;
use Tests\melia\ObjectStorage\Examples\Shop\Product;
use Tests\melia\ObjectStorage\Examples\Shop\ProductRepository;
use Tests\melia\ObjectStorage\Examples\Shop\ShopIndexes;
use Tests\melia\ObjectStorage\Examples\Shop\ShopStrategy;
use Tests\melia\ObjectStorage\TestCase;

require_once __DIR__ . '/Shop/Shop.php';

/**
 * The example of docs/examples/coming-from-doctrine.md, run: a shop as it would be built with Doctrine ORM,
 * with the object storage and its search indexes instead.
 */
final class ComingFromDoctrineTest extends TestCase
{
    private ObjectStorage $shop;
    private UniqueManager $unique;
    private Manager $search;
    private CustomerRepository $customers;
    private ProductRepository $products;
    private OrderRepository $orders;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('intl') || !class_exists(\Loupe\Loupe\Loupe::class)) {
            $this->markTestSkipped('The example uses Loupe, which needs loupe/loupe and ext-intl');
        }

        // the "EntityManager": a storage with the strategy of the shop, its unique and its search indexes
        $this->shop = new ObjectStorage($this->storageDirs[] = $this->createTemporaryDirectory(), strategy: new ShopStrategy());
        $this->unique = new UniqueManager($this->shop);
        $this->search = new Manager($this->shop, [new LoupeEngine()]);
        ShopIndexes::install($this->search, $this->unique);
        $this->unique->attach();
        $this->search->attach();

        $this->customers = new CustomerRepository($this->shop, $this->unique);
        $this->products = new ProductRepository($this->shop, $this->search, $this->unique);
        $this->orders = new OrderRepository($this->shop, $this->search);
    }

    public function tearDown(): void
    {
        if (isset($this->unique)) {
            $this->unique->detach();
        }
        if (isset($this->search)) {
            $this->search->detach();
            foreach ($this->search->list() as $index) {
                $index->close();
            }
        }
        parent::tearDown();
    }

    /**
     * @return array{Product, Product, Product}
     */
    private function catalog(): array
    {
        $chair = new Product('CH-1', 'Oak chair', 'A sturdy chair of solid oak', 89.0, stock: 4, tags: ['furniture', 'oak']);
        $table = new Product('TA-1', 'Oak table', 'A large dining table of oak', 349.0, stock: 0, tags: ['furniture', 'oak']);
        $lamp = new Product('LA-1', 'Desk lamp', 'A small lamp with a warm light', 29.9, stock: 12, tags: ['light']);
        foreach ([$chair, $table, $lamp] as $product) {
            $this->products->save($product);
        }
        return [$chair, $table, $lamp];
    }

    public function testEntitiesAreStoredAndFoundById(): void
    {
        $customer = $this->customers->register('Ada@Example.com', 'Ada');
        [$chair, , $lamp] = $this->catalog();

        $order = new Order($customer);
        $order->add($chair, 2);
        $order->add($lamp, 1);
        $uuid = $this->orders->save($order);

        $this->assertSame($uuid, $order->getUUID(), 'like a generated id after flush()');

        // in another request: find() and lazily loaded associations
        $loaded = (new ObjectStorage($this->shop->getStorageDir(), strategy: new ShopStrategy()))->load($uuid);
        $this->assertInstanceOf(LazyLoadReference::class, $loaded->customer, 'a proxy until used');
        $this->assertSame('Ada', $loaded->customer()->name);
        $this->assertSame(['Oak chair', 'Desk lamp'], array_map(static fn(OrderLine $line) => $line->product()->name, $loaded->lines()));
        $this->assertSame(207.9, $loaded->total);
    }

    public function testUniqueEmailAndSku(): void
    {
        $first = $this->customers->register('ada@example.com', 'Ada');
        $again = $this->customers->register(' ADA@example.com ', 'Someone else');

        $this->assertSame($first->getUUID(), $again->getUUID());
        $this->assertSame('Ada', $this->customers->findByEmail('Ada@Example.com')->name);
        $this->assertNull($this->customers->findByEmail('bob@example.com'));
        $this->assertSame(1, iterator_count($this->shop->list(Customer::class)));

        $this->catalog();
        try {
            $this->products->save(new Product('CH-1', 'Another chair', '', 10.0));
            $this->fail('a second product with the SKU was stored');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertSame('product-sku', $e->getIndexName()); // like Doctrine's UniqueConstraintViolationException
        }
        $this->assertSame('Oak chair', $this->products->findOneBySku('CH-1')->name);
    }

    public function testFullTextSearchWithFilters(): void
    {
        [$chair, $table, $lamp] = $this->catalog();

        $this->assertSame([$chair->getUUID(), $table->getUUID()], array_keys($this->products->search('oak')), 'ranked by relevance');
        $this->assertSame([$chair->getUUID()], array_keys($this->products->search('oak', maxPrice: 100)));
        $this->assertSame([$chair->getUUID()], array_keys($this->products->search('oak', inStockOnly: true)));
        $this->assertSame([$lamp->getUUID()], array_keys($this->products->search('lamp warm')), 'typo tolerant, all fields');
        $this->assertSame('Oak table', $this->products->findOneBySku('TA-1')?->name);

        // a changed product is found by its new text right away: attach() keeps the index up to date
        $lamp->description = 'A small lamp with a cold light';
        $this->products->save($lamp);
        $this->assertSame([], array_keys($this->products->search('warm')));
    }

    public function testOrdersOfACustomerPageByPage(): void
    {
        $ada = $this->customers->register('ada@example.com', 'Ada');
        $bob = $this->customers->register('bob@example.com', 'Bob');
        [$chair, , $lamp] = $this->catalog();

        $placed = [];
        foreach ([1, 2, 3] as $quantity) {
            $order = new Order($ada);
            $order->add($lamp, $quantity);
            $order->placedAt = 1_700_000_000 + $quantity; // newer with every order
            $this->orders->save($order);
            $placed[] = $order->getUUID();
        }
        $other = new Order($bob);
        $other->add($chair, 1);
        $this->orders->save($other);

        $first = $this->orders->findByCustomer($ada, page: 1, perPage: 2);
        $second = $this->orders->findByCustomer($ada, page: 2, perPage: 2);

        $this->assertSame(3, $first['total']);
        $this->assertSame([$placed[2], $placed[1]], array_keys($first['orders']), 'newest first');
        $this->assertSame([$placed[0]], array_keys($second['orders']));

        $shipped = $first['orders'][$placed[2]];
        $shipped->status = Order::STATUS_SHIPPED;
        $this->orders->save($shipped);
        $this->assertSame(['new' => 3, 'shipped' => 1], $this->orders->countByStatus());
        $this->assertSame([$placed[2]], array_keys($this->orders->findByCustomer($ada, status: Order::STATUS_SHIPPED)['orders']));

        // the search chooses the index of the class by the fields the request uses, see explain()
        $this->assertSame('orders', $this->search->explain(Order::class, new Request(filter: "status = 'new'"))->getChosen()?->name);
    }

    public function testOrdersAreFoundByTheNameOfTheirCustomer(): void
    {
        $ada = $this->customers->register('ada@example.com', 'Ada');
        $bob = $this->customers->register('bob@example.com', 'Bob');
        [$chair] = $this->catalog();

        $adasOrder = new Order($ada);
        $adasOrder->add($chair, 1);
        $this->orders->save($adasOrder);
        $bobsOrder = new Order($bob);
        $bobsOrder->add($chair, 2);
        $this->orders->save($bobsOrder);

        // a JOIN: the index holds the name of the customer with each order
        $byCustomerName = fn(string $name): array => array_keys(iterator_to_array($this->search->searchObjects(Order::class, new Request($name))));
        $this->assertSame([$adasOrder->getUUID()], $byCustomerName('Ada'));

        // renaming the customer updates their orders
        $ada->name = 'Ada Lovelace';
        $this->shop->store($ada);
        $this->assertSame([$adasOrder->getUUID()], $byCustomerName('Lovelace'));
        $this->assertSame([$bobsOrder->getUUID()], $byCustomerName('Bob'));
    }

    public function testRemovingAnOrderRemovesItsLinesOnly(): void
    {
        $customer = $this->customers->register('ada@example.com', 'Ada');
        [$chair] = $this->catalog();
        $order = new Order($customer);
        $order->add($chair, 1);
        $this->orders->save($order);
        $this->assertSame(1, iterator_count($this->shop->list(OrderLine::class)));

        $this->orders->remove($order);

        $this->assertSame(0, iterator_count($this->shop->list(OrderLine::class)), 'orphan removal');
        $this->assertNotNull($this->customers->findByEmail('ada@example.com'), 'the customer stays');
        $this->assertSame(3, iterator_count($this->shop->list(Product::class)), 'the products stay');
        $this->assertSame([], $this->orders->findByCustomer($customer)['orders'], 'and the index forgets it');
    }

    public function testOptimisticLockingLikeAVersionColumn(): void
    {
        [$chair] = $this->catalog();
        $uuid = $chair->getUUID();

        // two requests load the same product, each with its own storage instance
        $requestA = new ObjectStorage($this->shop->getStorageDir(), strategy: new ShopStrategy());
        $requestB = new ObjectStorage($this->shop->getStorageDir(), strategy: new ShopStrategy());
        $chairA = $requestA->load($uuid);
        $chairB = $requestB->load($uuid);

        $chairA->stock--;
        $requestA->store($chairA);

        $chairB->stock -= 2;
        $this->expectException(RevisionConflictException::class);
        $requestB->store($chairB); // stored by request A meanwhile: like Doctrine's OptimisticLockException
    }

    public function testATransactionLikeWrapInTransaction(): void
    {
        $customer = $this->customers->register('ada@example.com', 'Ada');
        [$chair] = $this->catalog();

        $order = new Order($customer);
        $order->add($chair, 2);
        $chair->stock -= 2;

        $transaction = (new Transaction($this->shop))->begin();
        $transaction->store($order);
        $transaction->store($chair, $chair->getUUID());
        $transaction->commit();

        $this->shop->clearCache();
        $this->assertSame(2, $this->shop->load($chair->getUUID())->stock);
        $this->assertSame(1, $this->orders->findByCustomer($customer)['total']);
    }
}
