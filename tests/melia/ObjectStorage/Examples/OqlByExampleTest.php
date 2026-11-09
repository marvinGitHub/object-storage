<?php

namespace Tests\melia\ObjectStorage\Examples;

use DateTimeImmutable;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Query\Oql;
use melia\ObjectStorage\Query\Result;
use melia\ObjectStorage\Unique\Definition as UniqueDefinition;
use melia\ObjectStorage\Unique\Manager as UniqueManager;
use Tests\melia\ObjectStorage\Examples\Oql\Category;
use Tests\melia\ObjectStorage\Examples\Oql\Customer;
use Tests\melia\ObjectStorage\Examples\Oql\Product;
use Tests\melia\ObjectStorage\Examples\Oql\Purchase;
use Tests\melia\ObjectStorage\Examples\Oql\PurchaseLine;
use Tests\melia\ObjectStorage\TestCase;

require_once __DIR__ . '/Oql/Model.php';

/**
 * docs/examples/oql-by-example.md: every ```sql block runs, in the order of the document, on the data of its
 * section "The example data"; a table after <!-- result --> must be the result of the last statement of the block.
 * A block after <!-- not run --> isn't run (e.g. it needs a changed class). The PHP blocks are repeated below.
 *
 * OQL_EXAMPLES_UPDATE=1 writes the results into the document instead of comparing them.
 */
class OqlByExampleTest extends TestCase
{
    private const string DOCUMENT = __DIR__ . '/../../../../docs/examples/oql-by-example.md';

    /** the data of the document: "The example data" */
    public static function seed(ObjectStorage $storage): void
    {
        $food = new Category('Food');
        $fruit = new Category('Fruit', $food);
        $furniture = new Category('Furniture');

        $apple = new Product('Apple', 1.5, $fruit, labels: ['bio', 'regional']);
        $banana = new Product('Banana', 2.0, $fruit, labels: ['bio']);
        $bread = new Product('Bread', 3.2, $food);
        $chair = new Product('Chair', 49.0, $furniture);
        $lamp = new Product('Lamp', 25.0, $furniture, active: false);
        foreach ([$apple, $banana, $bread, $chair, $lamp] as $product) {
            $storage->store($product);
        }

        $homa = new Customer('Homa', 'homa@example.com', 'Berlin', ['vip'], ['mobile' => '+49 170 1111111', 'work' => '+49 30 2222222']);
        $alex = new Customer('Alex', 'alex@example.com', 'Köln', [], ['mobile' => '+49 171 3333333']);
        $kim = new Customer('Kim', 'kim@example.com');
        foreach ([$homa, $alex, $kim] as $customer) {
            $storage->store($customer);
        }

        $storage->store(new Purchase('A-1', $homa, new DateTimeImmutable('2026-10-03T09:15:00Z'),
            [new PurchaseLine($apple, 4, 1.5), new PurchaseLine($bread, 1, 3.2)],
            status: 'shipped', shippedAt: new DateTimeImmutable('2026-10-04T14:00:00Z')));
        $storage->store(new Purchase('A-2', $homa, new DateTimeImmutable('2026-11-02T18:40:00Z'),
            [new PurchaseLine($chair, 2, 49.0)]));
        $storage->store(new Purchase('A-3', $alex, new DateTimeImmutable('2026-11-05T08:00:00Z'),
            [new PurchaseLine($banana, 6, 2.0), new PurchaseLine($apple, 2, 1.5)]));
    }

    public function testTheQueriesOfTheDocument(): void
    {
        self::seed($this->storage);
        $oql = new Oql($this->storage);
        $document = file_get_contents(self::DOCUMENT);
        $update = (bool)getenv('OQL_EXAMPLES_UPDATE');

        $pattern = '/(<!-- not run -->\n)?```sql\n(.*?)```\n(\n?<!-- result -->\n((?:\|[^\n]*\n)*))?/s';
        $blocks = 0;
        $tables = 0;
        $updated = preg_replace_callback($pattern, function (array $match) use ($oql, $update, &$blocks, &$tables): string {
            [$whole, $notRun, $sql] = $match;
            if ('' !== $notRun) {
                return $whole;
            }
            $blocks++;
            $result = null;
            foreach (preg_split('/;\s*\n/', trim($sql)) as $statement) {
                if ('' === trim(preg_replace('/^\s*--.*$/m', '', $statement))) {
                    continue;
                }
                try {
                    $result = $oql->query($statement);
                } catch (QueryException $e) {
                    $this->fail("$statement\n{$e->getMessage()}");
                }
            }
            if (!isset($match[3]) || '' === $match[3]) {
                return $whole;
            }
            $tables++;
            $table = self::table($result);
            if (!$update) {
                $this->assertSame($table, $match[4], "The result of\n$sql");
            }
            return substr($whole, 0, strlen($whole) - strlen($match[4])) . $table;
        }, $document);

        $this->assertGreaterThan(40, $blocks, 'the blocks run');
        $this->assertGreaterThan(30, $tables, 'the results compared');
        if ($update && $updated !== $document) {
            file_put_contents(self::DOCUMENT, $updated);
        }
    }

    /** a result as the document shows it */
    private static function table(Result $result): string
    {
        $cell = static fn(mixed $value): string => str_replace('|', '\|', match (true) {
            null === $value => 'NULL',
            true === $value => 'TRUE',
            false === $value => 'FALSE',
            is_string($value) => $value,
            default => json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        });
        $lines = ['| ' . implode(' | ', $result->columns) . ' |', '|' . str_repeat('---|', count($result->columns))];
        foreach ($result->rows as $row) {
            $lines[] = '| ' . implode(' | ', array_map($cell, $row)) . ' |';
        }
        return implode("\n", $lines) . "\n";
    }

    // ---- the PHP blocks of the document ----

    public function testRunningQueriesFromPhp(): void
    {
        self::seed($this->storage);
        $oql = new Oql($this->storage);

        $result = $oql->query(
            'SELECT name, price FROM Product WHERE price <= :max AND category.title = :category ORDER BY price',
            ['max' => 10, 'category' => 'Fruit'],
        );
        $this->assertSame(['name', 'price'], $result->columns);
        $this->assertSame([['Apple', 1.5], ['Banana', 2]], $result->rows);
        $this->assertSame([['name' => 'Apple', 'price' => 1.5], ['name' => 'Banana', 'price' => 2]], iterator_to_array($result));
        $this->assertSame(['Apple', 'Banana'], $result->column('name'));
        $this->assertCount(2, $result);

        $this->assertSame([['Bread']], $oql->query('SELECT name FROM Product WHERE price BETWEEN ? AND ?', [3, 4])->rows);

        $since = $oql->query('SELECT number FROM Purchase WHERE createdAt >= :since ORDER BY number', ['since' => new DateTimeImmutable('2026-11-01 00:00', new \DateTimeZone('Europe/Berlin'))]);
        $this->assertSame(['A-2', 'A-3'], $since->column('number'));

        $first = $oql->query('SELECT name FROM Product ORDER BY name', maxRows: 2);
        $this->assertSame(['Apple', 'Banana'], $first->column(0));
        $this->assertTrue($first->truncated);
    }

    public function testWritingFromPhp(): void
    {
        self::seed($this->storage);
        $oql = new Oql($this->storage);

        $kim = $oql->query("SELECT UUID FROM Customer WHERE name = 'Kim'")->rows[0][0];
        $uuids = $oql->query(
            'INSERT INTO Purchase (number, customer, createdAt) VALUES (:number, REF(:customer), NOW())',
            ['number' => 'A-4', 'customer' => $kim],
        )->column(0);
        $this->assertCount(1, $uuids);
        $purchase = $this->storage->load($uuids[0]);
        $this->assertSame('Kim', $purchase->customer->name);
        $this->assertSame([], $purchase->lines);
        $this->assertSame('open', $purchase->status);
        $this->assertEqualsWithDelta(time(), $purchase->createdAt->getTimestamp(), 60);

        $dry = $oql->query("UPDATE Product SET price = price * 0.9 WHERE category.title = 'Furniture'", dryRun: true);
        $this->assertTrue($dry->dryRun);
        $this->assertSame(2, $dry->affected());
        $this->assertSame([[49]], $oql->query("SELECT price FROM Product WHERE name = 'Chair'")->rows, 'nothing written');

        // the errors shown, with the class of the document without namespace
        foreach ([
                     "UPDATE Product SET price = 'cheap'" => "price of Product is float, not the text 'cheap'",
                     "UPDATE Product SET color = 'red'" => 'Product has no property color',
                     'UPDATE Product SET category = NULL' => "category of Product is Category: NULL isn't possible",
                 ] as $query => $message) {
            try {
                $oql->query($query);
                $this->fail($query);
            } catch (QueryException $e) {
                $this->assertSame($message, str_replace('Tests\\melia\\ObjectStorage\\Examples\\Oql\\', '', $e->getMessage()), $query);
            }
        }

        $this->assertTrue(Oql::writes('DELETE FROM Product WHERE active = FALSE'));
        $this->assertFalse(Oql::writes('SELECT name FROM Product'));
    }

    public function testAddingADateProperty(): void
    {
        self::seed($this->storage);
        $oql = new Oql($this->storage);
        // stored before the property was added: the data has no "paidAt"
        $uuid = $oql->query("SELECT UUID FROM Purchase WHERE number = 'A-1'")->rows[0][0];
        $raw = $this->storage->readRaw($uuid);
        $data = json_decode($raw->data, true);
        unset($data['paidAt']);
        $json = json_encode($data);
        $metadata = clone $raw->metadata;
        $metadata->setChecksum(hash($metadata->getChecksumAlgorithm(), $json));
        $this->storage->writeRaw(new \melia\ObjectStorage\Transfer\RawObject($uuid, $json, $metadata), true);
        $this->storage->clearCache();

        $this->assertNull($this->storage->load($uuid)->paidAt, 'the default of the declared property');
        $this->assertSame([[null]], $oql->query("SELECT paidAt FROM Purchase WHERE number = 'A-1'")->rows);
        $this->assertSame(1, $oql->query("UPDATE Purchase SET paidAt = shippedAt WHERE status = 'shipped' AND paidAt IS NULL")->affected());
        $this->storage->clearCache();
        $this->assertSame('2026-10-04T14:00:00+00:00', $this->storage->load($uuid)->paidAt->format(DATE_ATOM));
    }

    public function testSearchIndexes(): void
    {
        if (!class_exists(\Loupe\Loupe\LoupeFactory::class) || !extension_loaded('intl')) {
            $this->markTestSkipped('Loupe needs loupe/loupe and ext-intl');
        }
        self::seed($this->storage);
        $search = new \melia\ObjectStorage\Search\Index\Manager($this->storage, [new \melia\ObjectStorage\Search\Loupe\LoupeEngine()]);
        $search->create(new \melia\ObjectStorage\Search\Index\Definition('products', Product::class, [
            new \melia\ObjectStorage\Search\Field('name', 'name', searchable: true, filterable: true),
            new \melia\ObjectStorage\Search\Field('price', 'price', \melia\ObjectStorage\Search\FieldType::NUMBER, searchable: false, filterable: true),
            new \melia\ObjectStorage\Search\Field('active', 'active', \melia\ObjectStorage\Search\FieldType::BOOLEAN, searchable: false, filterable: true),
            new \melia\ObjectStorage\Search\Field('category', 'category.title', searchable: false, filterable: true),
        ]))->build();
        $search->attach();
        try {
            $oql = new Oql($this->storage, $search);
            $row = $oql->query('EXPLAIN SELECT name FROM Product WHERE price BETWEEN 2 AND 30 AND active = TRUE')->rows[0];
            $this->assertSame(['search index', 'products: ((price >= 2 AND price <= 30) OR price IS NULL) AND (active = true OR active IS NULL), 2 object(s)'], [$row[2], $row[3]]);
            $this->assertSame('search index', $oql->query("EXPLAIN SELECT name FROM Product WHERE category.title = 'Fruit'")->rows[0][2]);
            $this->assertSame('all objects of the class', $oql->query('EXPLAIN SELECT name FROM Product WHERE price > 10 OR active = FALSE')->rows[0][2]);
            $this->assertSame([['Banana'], ['Bread']], $oql->query('SELECT name FROM Product WHERE price BETWEEN 2 AND 30 AND active = TRUE ORDER BY name')->rows);
        } finally {
            $search->detach();
        }
    }

    public function testExplain(): void
    {
        self::seed($this->storage);
        $unique = new UniqueManager($this->storage);
        $unique->create(new UniqueDefinition('customer-email', Customer::class, 'email'));
        $oql = new Oql($this->storage, unique: $unique);

        $explained = $oql->query("EXPLAIN SELECT name FROM Customer WHERE email = 'kim@example.com'");
        $this->assertSame(['source', 'class', 'access', 'details'], $explained->columns);
        $this->assertSame('unique index', $explained->rows[0][2]);
        $this->assertSame('all objects of the class', $oql->query("EXPLAIN SELECT name FROM Customer WHERE city = 'Berlin'")->rows[0][2]);
        $this->assertSame([['Kim']], $oql->query("SELECT name FROM Customer WHERE email = 'kim@example.com'")->rows);
    }
}
