<?php

namespace Tests\melia\ObjectStorage\Query;

use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Query\Oql;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\Manager as SearchManager;
use melia\ObjectStorage\Search\Request;
use melia\ObjectStorage\Unique\Definition as UniqueDefinition;
use melia\ObjectStorage\Unique\Manager as UniqueManager;
use stdClass;
use Tests\melia\ObjectStorage\Search\ArrayEngine;
use Tests\melia\ObjectStorage\Search\Category;
use Tests\melia\ObjectStorage\Search\Product;
use Tests\melia\ObjectStorage\TestCase;

/**
 * The keyword UUID, and INSERT, UPDATE and DELETE: written as stored, checked against the classes, before the first
 * object is written; references kept, unique and search indexes following.
 */
class OqlWriteTest extends TestCase
{
    private Oql $oql;
    private string $fruit;
    private string $furniture;
    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();
        $fruit = new Category('Fruit');
        $furniture = new Category('Furniture');
        $this->products['apple'] = $this->storage->store(new Product('Apple', 'red', 1.5, ['red'], $fruit));
        $this->products['banana'] = $this->storage->store(new Product('Banana', 'yellow', 2, [], $fruit));
        $this->products['chair'] = $this->storage->store(new Product('Chair', 'oak', 49, [], $furniture, false));
        $this->oql = new Oql($this->storage);
        $this->fruit = $this->value("SELECT UUID FROM Category WHERE title = 'Fruit'");
        $this->furniture = $this->value("SELECT UUID FROM Category WHERE title = 'Furniture'");
    }

    private function rows(string $query, array $parameters = []): array
    {
        return $this->oql->query($query, $parameters)->rows;
    }

    private function value(string $query, array $parameters = []): mixed
    {
        return $this->rows($query, $parameters)[0][0] ?? null;
    }

    /** the objects as loaded from the files, as another process would */
    private function load(string $uuid): ?object
    {
        $this->storage->clearCache();
        return $this->storage->load($uuid);
    }

    // ---- UUID ----

    public function testTheUuidOfObjects(): void
    {
        $apple = $this->products['apple'];
        $this->assertSame([[$apple, 'Apple']], $this->rows('SELECT UUID, name FROM Product WHERE UUID = ?', [$apple]));
        $this->assertSame([[$apple]], $this->rows("SELECT uuid FROM Product WHERE name = 'Apple'"), 'a keyword in any case');
        $this->assertSame([[$apple, $this->fruit]], $this->rows("SELECT p.UUID, p.category.UUID FROM Product p WHERE p.name = 'Apple'"));
        $this->assertSame([[null]], $this->rows("SELECT name.UUID FROM Product WHERE name = 'Apple'"), 'not a reference');
        $this->assertSame(
            [['Apple'], ['Banana']],
            $this->rows('SELECT p.name FROM Product p JOIN Category c ON c.UUID = p.category.UUID WHERE c.UUID = :fruit ORDER BY p.name', ['fruit' => $this->fruit])
        );
        $this->assertSame([[2]], $this->rows('SELECT COUNT(*) FROM Product WHERE UUID IN (?, ?)', [$apple, $this->products['chair']]));
        $sorted = $this->products;
        sort($sorted);
        $this->assertSame(array_map(static fn($u) => [$u], $sorted), $this->rows('SELECT UUID FROM Product ORDER BY UUID'));
        $this->assertSame([[3]], $this->rows('SELECT COUNT(DISTINCT UUID) FROM Product'));
    }

    public function testAPropertyNamedUuid(): void
    {
        $object = new stdClass();
        $object->uuid = 'not the UUID';
        $uuid = $this->storage->store($object);
        $this->assertSame([[$uuid, 'not the UUID']], $this->rows('SELECT UUID, `uuid` FROM stdClass'));
    }

    public function testUuidNeedsTheAliasWithJoins(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('UUID needs the alias of its source, e.g. p.UUID');
        $this->oql->query('SELECT UUID FROM Product p JOIN Category c ON c = p.category');
    }

    // ---- INSERT ----

    public function testInsert(): void
    {
        $result = $this->oql->query("INSERT INTO Category (title) VALUES ('Tools'), (:title)", ['title' => 'Toys']);
        $this->assertSame('INSERT', $result->statement);
        $this->assertSame(2, $result->affected());
        [$tools, $toys] = $result->column(0);

        $category = $this->load($tools);
        $this->assertInstanceOf(Category::class, $category);
        $this->assertSame('Tools', $category->title);
        $this->assertNull($category->parent, 'the default of the constructor');
        $this->assertSame('Toys', $this->load($toys)->title);
        $this->assertSame(1, $this->storage->getRevision($tools));
        $this->assertSame([['Fruit'], ['Furniture'], ['Tools'], ['Toys']], $this->rows('SELECT title FROM Category ORDER BY title'));
    }

    public function testInsertWithAReferenceAndDefaults(): void
    {
        $lamp = $this->oql->query("INSERT INTO Product (name, price, category) VALUES ('Lamp', 20, REF(:category))", ['category' => $this->furniture])->column(0)[0];
        $product = $this->load($lamp);
        $this->assertSame('Furniture', $product->category->title);
        $this->assertSame([], $product->tags, 'defaults of the class');
        $this->assertTrue($product->active);
        $this->assertSame('', $product->description);
        $this->assertSame([[2]], $this->rows('SELECT COUNT(*) FROM Category'), 'the category isn\'t copied');
        $this->assertSame([['Chair'], ['Lamp']], $this->rows('SELECT p.name FROM Product p WHERE p.category.title = ? ORDER BY 1', ['Furniture']));
    }

    public function testInsertIntoAClassWithDynamicProperties(): void
    {
        $uuid = $this->oql->query("INSERT INTO stdClass (name, size, active) VALUES ('Box', 1.5, TRUE)")->column(0)[0];
        $object = $this->load($uuid);
        $this->assertSame(['Box', 1.5, true], [$object->name, $object->size, $object->active]);
    }

    public function testADryRunWritesNothing(): void
    {
        $result = $this->oql->query("INSERT INTO Category (title) VALUES ('Tools')", [], true);
        $this->assertTrue($result->dryRun);
        $this->assertSame([[null]], $result->rows);
        $this->assertSame(1, $result->affected());

        $result = $this->oql->query('UPDATE Product SET price = 0 WHERE price > 1', [], true);
        $this->assertCount(3, $result->rows);
        $result = $this->oql->query('DELETE FROM Product', [], true);
        $this->assertCount(3, $result->rows);
        $this->assertSame([[2]], $this->rows('SELECT COUNT(*) FROM Category'));
        $this->assertSame([[3, 52.5]], $this->rows('SELECT COUNT(*), SUM(price) FROM Product'));
    }

    // ---- UPDATE ----

    public function testUpdate(): void
    {
        $result = $this->oql->query("UPDATE Product SET price = price * 2, name = name || '!' WHERE category.title = 'Fruit'");
        $this->assertSame(2, $result->affected());
        // 3.0 is written as 3, as store() does (JSON); a float property gets the float back when loaded
        $this->assertSame([['Apple!', 3], ['Banana!', 4], ['Chair', 49]], $this->rows('SELECT name, price FROM Product ORDER BY name'));

        $apple = $this->load($this->products['apple']);
        $this->assertSame('Apple!', $apple->name, 'loaded again: data and checksum fit');
        $this->assertSame(3.0, (float)$apple->price);
        $this->assertSame('Fruit', $apple->category->title, 'the reference is kept');
        $this->assertSame(['red'], $apple->tags, 'other properties are kept');
        $this->assertSame(2, $this->storage->getRevision($this->products['apple']));
        $this->assertSame([[2]], $this->rows('SELECT COUNT(*) FROM Category'), 'the category isn\'t copied');
    }

    public function testUpdateUsesTheValuesBefore(): void
    {
        $this->oql->query("UPDATE Product SET price = 10, description = description || price WHERE name = 'Banana'");
        $this->assertSame([['yellow2', 10]], $this->rows("SELECT description, price FROM Product WHERE name = 'Banana'"));
    }

    public function testUpdateReferences(): void
    {
        $this->oql->query('UPDATE Product SET category = REF(:c) WHERE UUID = :u', ['c' => $this->furniture, 'u' => $this->products['apple']]);
        $this->assertSame('Furniture', $this->load($this->products['apple'])->category->title);
        $this->oql->query("UPDATE Product SET category = NULL WHERE name = 'Banana'");
        $this->assertNull($this->load($this->products['banana'])->category);
        // a path to the UUID: the category of the chair for the apple
        $this->oql->query("UPDATE Product SET category = REF(category.UUID) WHERE name = 'Chair'");
        $this->assertSame([[2]], $this->rows('SELECT COUNT(*) FROM Category'));
    }

    public function testUpdateCountsChangedObjects(): void
    {
        $this->assertSame(1, $this->oql->query('UPDATE Product SET price = 2 WHERE price <= 2')->affected(), 'the banana costs 2 already');
        $this->assertSame(0, $this->oql->query("UPDATE Product SET price = 1 WHERE name = 'nothing'")->affected());
    }

    public function testAnErrorWritesNothing(): void
    {
        // the banana (price 2) would get NULL as name (a division by 0), which a string can't be; the apple, read
        // before it, gets a name: but nothing is written, as all values are checked first
        try {
            $this->oql->query("UPDATE Product SET description = 'changed', name = SUBSTR(name, 1, 3) || (price / (price - 2))");
            $this->fail('NULL for a string');
        } catch (QueryException $e) {
            $this->assertStringContainsString('name of Tests\melia\ObjectStorage\Search\Product is string: NULL isn\'t possible', $e->getMessage());
        }
        $this->assertSame([['red'], ['yellow'], ['oak']], $this->rows('SELECT description FROM Product ORDER BY price'), 'not even the first one');
    }

    public function testTypes(): void
    {
        $errors = [
            "INSERT INTO Product (name) VALUES (1)" => 'name of Tests\melia\ObjectStorage\Search\Product is string, not the number 1',
            "INSERT INTO Product (name, active) VALUES ('x', 1)" => 'active of Tests\melia\ObjectStorage\Search\Product is bool, not the number 1',
            "INSERT INTO Product (name, nope) VALUES ('x', 1)" => 'Tests\melia\ObjectStorage\Search\Product has no property nope',
            "INSERT INTO Product (name, category) VALUES ('x', 'a uuid as text')" => 'category of Tests\melia\ObjectStorage\Search\Product is ?Tests\melia\ObjectStorage\Search\Category, not the text',
            "INSERT INTO Product (name, category) VALUES ('x', REF('00000000-0000-4000-8000-000000000000'))" => 'there is no such object',
            "INSERT INTO Category (title) VALUES (NULL)" => 'title of Tests\melia\ObjectStorage\Search\Category is string: NULL isn\'t possible',
            "INSERT INTO Category (parent) VALUES (NULL)" => 'title of Tests\melia\ObjectStorage\Search\Category needs a value: it has no default',
            "INSERT INTO `Some\\Unknown\\Thing` (a) VALUES (1)" => 'Unknown class',
            "UPDATE Product SET tags = 'x'" => 'tags of Tests\melia\ObjectStorage\Search\Product is array, not the text',
            "UPDATE Product SET name = name WHERE REF(name) = 1" => 'REF() is for the values of INSERT and UPDATE',
            "UPDATE Product SET name = LOWER(REF(name))" => 'REF() is a whole value',
            "UPDATE Product SET name = 'a', name = 'b'" => 'A property is set twice',
            "SELECT REF(name) FROM Product" => 'REF() is for the values of INSERT and UPDATE',
            "INSERT INTO Category (title) VALUES (title)" => 'there is no object to read it of',
        ];
        foreach ($errors as $query => $message) {
            try {
                $this->oql->query($query);
                $this->fail("No error: $query");
            } catch (QueryException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $query);
            }
        }
        // the category of a product is a category
        try {
            $this->oql->query('UPDATE Product SET category = REF(:p)', ['p' => $this->products['chair']]);
            $this->fail('a product as category');
        } catch (QueryException $e) {
            $this->assertStringContainsString('REF(\'' . $this->products['chair'] . '\') is a Tests\melia\ObjectStorage\Search\Product', $e->getMessage());
        }
        $this->assertSame([[3]], $this->rows('SELECT COUNT(*) FROM Product'));
    }

    public function testNumbersFitTheTypes(): void
    {
        $uuid = $this->oql->query("INSERT INTO `Tests\melia\ObjectStorage\Query\Person` (name, age, score) VALUES ('Ada', 36.0, 7)")->column(0)[0];
        $person = $this->load($uuid);
        $this->assertSame(36, $person->age, '36.0 is the int 36');
        $this->assertSame(7, $person->score);
        try {
            $this->oql->query('UPDATE Person SET age = age / 5');
            $this->fail('7.2 for an int');
        } catch (QueryException $e) {
            $this->assertStringContainsString('age of Tests\melia\ObjectStorage\Query\Person is ?int, not the number 7.2', $e->getMessage());
        }
        $this->oql->query('UPDATE Person SET age = age / 4, score = score / 2');
        $person = $this->load($uuid);
        $this->assertSame([9, 3.5], [$person->age, $person->score]);
    }

    // ---- DELETE ----

    public function testDelete(): void
    {
        $result = $this->oql->query('DELETE FROM Product WHERE price > ? OR UUID = ?', [10, $this->products['apple']]);
        $this->assertSame('DELETE', $result->statement);
        $this->assertEqualsCanonicalizing([$this->products['chair'], $this->products['apple']], $result->column(0));
        $this->assertSame([['Banana']], $this->rows('SELECT name FROM Product'));
        $this->assertFalse($this->storage->exists($this->products['chair']));
        $this->assertSame([[2]], $this->rows('SELECT COUNT(*) FROM Category'), 'referenced objects stay (child deletion policy)');
        $this->assertSame(1, $this->oql->query('DELETE FROM Product')->affected());
    }

    // ---- indexes ----

    public function testUniqueIndexesAreChecked(): void
    {
        $unique = new UniqueManager($this->storage);
        $unique->create(new UniqueDefinition('product-name', Product::class, ['name']));
        $unique->attach();
        try {
            try {
                $this->oql->query("INSERT INTO Product (name) VALUES ('Apple')");
                $this->fail('a second apple');
            } catch (QueryException $e) {
                $this->assertStringContainsString('Stopped after 0 of 1 objects were inserted', $e->getMessage());
            }
            try {
                $this->oql->query("UPDATE Product SET name = 'Fruit' WHERE category.title = 'Fruit'");
                $this->fail('two of the same name');
            } catch (QueryException $e) {
                $this->assertStringContainsString('Stopped after 1 of 2 objects were updated', $e->getMessage());
                $this->assertStringContainsString('the statement was rolled back, nothing was written', $e->getMessage());
            }
            // every statement is atomic: the object renamed before the violation is restored
            $this->assertSame([[0]], $this->rows("SELECT COUNT(*) FROM Product WHERE name = 'Fruit'"));
            // the name of a deleted product is free again
            $this->oql->query("DELETE FROM Product WHERE name = 'Chair'");
            $this->oql->query("INSERT INTO Product (name) VALUES ('Chair')");
        } finally {
            $unique->detach();
        }
    }

    public function testSearchIndexesFollow(): void
    {
        $search = new SearchManager($this->storage, [new ArrayEngine()]);
        $index = $search->create(new Definition('products', Product::class, [new Field('name', 'name'), new Field('category', 'category.title', filterable: true)]));
        $index->build();
        $search->attach();
        try {
            $find = static fn(string $filter): array => $index->search(new Request(filter: $filter))->getUuids();
            $this->oql->query("UPDATE Product SET name = 'Pear' WHERE name = 'Apple'");
            $this->assertSame([$this->products['apple']], $find("name = 'Pear'"));
            $lamp = $this->oql->query("INSERT INTO Product (name, category) VALUES ('Lamp', REF(?))", [$this->furniture])->column(0)[0];
            $this->assertSame([$lamp], $find("name = 'Lamp'"));
            $this->oql->query("DELETE FROM Product WHERE name = 'Lamp'");
            $this->assertSame([], $find("name = 'Lamp'"));
        } finally {
            $search->detach();
        }
    }
}
