<?php

namespace Tests\melia\ObjectStorage\Query;

use DateTimeImmutable;
use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Query\Oql;
use melia\ObjectStorage\Query\Result;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\melia\ObjectStorage\Search\Category;
use Tests\melia\ObjectStorage\Search\Product;
use Tests\melia\ObjectStorage\Search\Tag;
use Tests\melia\ObjectStorage\TestCase;

/**
 * OQL beyond what OqlSqliteTest compares with SQLite: the selection of all properties, parameters, class names,
 * references to objects that are gone, lists, case of letters beyond ASCII, and the errors.
 */
class OqlTest extends TestCase
{
    private Oql $oql;
    private array $uuids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $food = new Category('Food');
        $fruit = new Category('Fruit', $food);
        $furniture = new Category('Furniture');
        $this->uuids['apple'] = $this->storage->store(new Product('Apple', 'red', 1.5, ['red', 'sweet'], $fruit, labels: [new Tag('bio')]));
        $this->uuids['banana'] = $this->storage->store(new Product('Banana', 'yellow', 2, ['yellow'], $fruit));
        $this->uuids['chair'] = $this->storage->store(new Product('Chair', 'oak', 49, [], $furniture, false));
        $this->uuids['äpfel'] = $this->storage->store(new Product('ÄPFEL', 'Öl', null, [], null));
        $this->oql = new Oql($this->storage);
    }

    private function query(string $query, array $parameters = []): array
    {
        return $this->oql->query($query, $parameters)->rows;
    }

    public function testSelectingProperties(): void
    {
        $result = $this->oql->query('SELECT name, price FROM Product ORDER BY name');
        $this->assertSame(['name', 'price'], $result->columns);
        $this->assertSame([['Apple', 1.5], ['Banana', 2], ['Chair', 49], ['ÄPFEL', null]], $result->rows);
        $this->assertCount(4, $result);
        $this->assertSame(['Apple', 'Banana', 'Chair', 'ÄPFEL'], $result->column('name'));
        $this->assertSame([], $result->column('nope'));
    }

    public function testAllPropertiesWithStar(): void
    {
        $result = $this->oql->query('SELECT * FROM Category ORDER BY title');
        // the properties as stored: sorted by name
        $this->assertSame(['parent', 'title'], $result->columns);
        $food = $this->query("SELECT c FROM Category c WHERE c.title = 'Food'")[0][0];
        $this->assertSame([[null, 'Food'], [$food, 'Fruit'], [null, 'Furniture']], $result->rows, 'references as UUIDs');

        $result = $this->oql->query('SELECT p.name, c.* FROM Product p JOIN Category c ON c = p.category ORDER BY p.name');
        $this->assertSame(['p.name', 'c.parent', 'c.title'], $result->columns);
        $result = $this->oql->query("SELECT * FROM Product p JOIN Category c ON c = p.category WHERE p.name = 'Chair'");
        $this->assertContains('p.name', $result->columns);
        $this->assertContains('c.title', $result->columns);
    }

    public function testTheAliasAloneIsTheUuid(): void
    {
        $this->assertSame([[$this->uuids['apple']]], $this->query("SELECT p FROM Product p WHERE p.name = 'Apple'"));
        $this->assertSame([[$this->uuids['apple']]], $this->query("SELECT Product FROM Product WHERE name = 'Apple'"), 'the short name of the class without an alias');
    }

    public function testPathsFollowReferences(): void
    {
        $this->assertSame(
            [['Apple', 'Fruit', 'Food', null], ['Chair', 'Furniture', null, null], ['ÄPFEL', null, null, null]],
            $this->query("SELECT name, category.title, category.parent.title, category.parent.parent.title FROM Product WHERE name != 'Banana' ORDER BY name")
        );
        // a list of references: the values of all of them; through an empty list NULL
        $this->assertSame([[['bio']], [null]], $this->query("SELECT labels.title FROM Product WHERE name IN ('Apple', 'Chair') ORDER BY name"));
        $this->assertSame([[['red', 'sweet']]], $this->query("SELECT tags FROM Product WHERE name = 'Apple'"), 'lists as arrays');
    }

    public function testObjectsThatAreGone(): void
    {
        $furniture = $this->query("SELECT category FROM Product WHERE name = 'Chair'")[0][0];
        $this->storage->delete($furniture);
        $this->assertSame([['Chair', null]], $this->query("SELECT name, category.title FROM Product WHERE name = 'Chair'"), 'a deleted referenced object is NULL');
        $this->assertSame([], $this->query("SELECT p.name FROM Product p JOIN Category c ON c = p.category WHERE p.name = 'Chair'"));

        $this->storage->setExpiration($this->uuids['banana'], new DateTimeImmutable('-1 second'));
        $this->assertSame([['Apple'], ['Chair'], ['ÄPFEL']], $this->query('SELECT name FROM Product ORDER BY name'), 'expired objects aren\'t read');
    }

    public function testParameters(): void
    {
        $this->assertSame([['Apple'], ['Banana']], $this->query('SELECT name FROM Product WHERE price BETWEEN ? AND ? ORDER BY name', [1, 10]));
        $this->assertSame([['Chair']], $this->query('SELECT name FROM Product WHERE name = :name AND active = :active', ['name' => 'Chair', 'active' => false]));
        $this->assertSame([["O'Neill' OR 1=1"]], $this->query('SELECT :v FROM Product WHERE name = ?', ['Apple', 'v' => "O'Neill' OR 1=1"]), 'never part of the query');
        $this->assertSame([['Banana']], $this->query('SELECT name FROM Product ORDER BY name LIMIT ? OFFSET ?', [1, 1]));
    }

    public function testClassNames(): void
    {
        $this->assertCount(4, $this->query('SELECT name FROM `Tests\melia\ObjectStorage\Search\Product`'));
        $this->assertCount(4, $this->query('SELECT name FROM Tests\melia\ObjectStorage\Search\Product'));
        $this->assertCount(4, $this->query('SELECT name FROM product'), 'class names ignore case, as in PHP');
        $this->assertSame([], $this->query('SELECT name FROM `Tests\melia\ObjectStorage\Query\Company`'), 'a class without objects');
    }

    public function testLikeIgnoresCaseOfAllLetters(): void
    {
        $this->assertSame([['ÄPFEL']], $this->query("SELECT name FROM Product WHERE name LIKE 'äp%'"));
        $this->assertSame([['ÄPFEL']], $this->query("SELECT name FROM Product WHERE LOWER(name) = 'äpfel'"));
        $this->assertSame([[5]], $this->query("SELECT LENGTH(name) FROM Product WHERE description = 'Öl'"), 'characters, not bytes');
        $this->assertSame([['Chair']], $this->query("SELECT name FROM Product WHERE description LIKE '_a\\k' OR description LIKE 'o_k'"));
    }

    public function testGrouping(): void
    {
        $this->assertSame(
            [['Fruit', 2, 3.5, 1.75, 'Apple', 'Banana'], [null, 1, null, null, 'ÄPFEL', 'ÄPFEL'], ['Furniture', 1, 49, 49.0, 'Chair', 'Chair']],
            $this->query('SELECT category.title AS c, COUNT(*), SUM(price), AVG(price), MIN(name), MAX(name) FROM Product GROUP BY c ORDER BY 2 DESC, c')
        );
        $this->assertSame([['Fruit', 2]], $this->query('SELECT category.title, COUNT(*) AS n FROM Product GROUP BY 1 HAVING n > 1'));
        $this->assertSame([[4, 3, 2]], $this->query('SELECT COUNT(*), COUNT(price), COUNT(DISTINCT category) FROM Product'));
        $this->assertSame([[0, null]], $this->query("SELECT COUNT(*), MAX(price) FROM Product WHERE name = 'nothing'"), 'aggregates of no rows');
        $this->assertSame([], $this->query("SELECT name, COUNT(*) FROM Product WHERE name = 'nothing' GROUP BY name"));
    }

    public function testOrdering(): void
    {
        $this->assertSame([[null], [1.5], [2], [49]], $this->query('SELECT price FROM Product ORDER BY price'), 'NULL first');
        $this->assertSame([[49], [2], [1.5], [null]], $this->query('SELECT price FROM Product ORDER BY price DESC'));
        // an alias of the selection wins over a property of the same name: by the description, not by the name
        $this->assertSame(
            [['oak', 'Chair'], ['red', 'Apple'], ['yellow', 'Banana']],
            $this->query('SELECT description AS name, name AS n FROM Product WHERE price < 100 ORDER BY name')
        );
        // stable: equal values keep the order in which the objects were read
        $this->assertSame([['Fruit'], ['Fruit']], $this->query("SELECT category.title FROM Product WHERE category.title = 'Fruit' ORDER BY 1"));
    }

    public function testNumbersAsText(): void
    {
        // objects have no schema: a price may be stored as text
        $this->storage->store(new Product('Table', price: '120.50'));
        $this->assertSame([['Chair'], ['Table']], $this->query('SELECT name FROM Product WHERE price > 10 ORDER BY name'));
        $this->assertSame([['Table'], ['Chair'], ['Banana']], $this->query('SELECT name FROM Product WHERE price >= 2 ORDER BY price DESC'));
        // joined by hashing and by trying every pair alike
        $this->assertSame([['Table', 'Table']], $this->query("SELECT a.name, b.name FROM Product a JOIN Product b ON b.price = a.price WHERE a.name = 'Table'"));
        $this->assertSame([['Table', 'Table']], $this->query("SELECT a.name, b.name FROM Product a JOIN Product b ON b.price = a.price OR 1 = 0 WHERE a.name = 'Table'"));
    }

    public function testDistinctLimitOffset(): void
    {
        $this->assertSame([['Furniture'], ['Fruit'], [null]], $this->query('SELECT DISTINCT category.title FROM Product ORDER BY 1 DESC'));
        $this->assertSame([['Banana'], ['Chair']], $this->query('SELECT name FROM Product ORDER BY name LIMIT 2 OFFSET 1'));
        $this->assertSame([], $this->query('SELECT name FROM Product LIMIT 0'));
        $this->assertSame([['ÄPFEL']], $this->query('SELECT name FROM Product ORDER BY name OFFSET 3'), 'OFFSET without LIMIT');
    }

    public function testCommentsQuotingAndCase(): void
    {
        $this->assertSame([['Apple']], $this->query("select `name` -- the name\n from Product /* all of them */ where \"name\" = 'Apple';"));
    }

    public function testResultRows(): void
    {
        $result = new Result(['a', 'b', 'a'], [[1, 2, 3]]);
        $this->assertSame([['a' => 1, 'b' => 2, 'a (3)' => 3]], $result->toArray());
        $this->assertSame([['a' => 1, 'b' => 2, 'a (3)' => 3]], iterator_to_array($result));
    }

    public static function errors(): array
    {
        return [
            'syntax' => ['SELECT name FROM Product WHERE', 'Unexpected end of the query'],
            'position' => ["SELECT name\nFROM Product WHERE name = = 1", 'line 2, column 27'],
            'string' => ["SELECT 'open FROM Product", 'Unterminated string'],
            'unknown class' => ['SELECT x FROM Nope', 'Unknown class "Nope"'],
            'alias needed' => ['SELECT name FROM Product p JOIN Category c ON c = p.category', '"name" needs the alias of its source (p, c)'],
            'alias twice' => ['SELECT p.name FROM Product p JOIN Category p ON 1 = 1', 'The alias "p" is used twice'],
            'not joined yet' => ['SELECT p.name FROM Product p JOIN Category c ON c = d.category JOIN Category d ON 1 = 1', '"d" isn\'t joined yet'],
            'not grouped' => ['SELECT name, COUNT(*) FROM Product', 'Product.name must be in GROUP BY or in an aggregate'],
            'aggregate in where' => ['SELECT name FROM Product WHERE COUNT(*) > 1', 'COUNT isn\'t possible here'],
            'nested aggregate' => ['SELECT MAX(COUNT(*)) FROM Product', 'COUNT inside of an aggregate'],
            'unknown function' => ['SELECT NOPE(name) FROM Product', 'Unknown function NOPE'],
            'arguments' => ['SELECT LOWER(name, 1) FROM Product', 'LOWER takes 1 argument'],
            'star' => ['SELECT SUM(*) FROM Product', 'SUM(*) isn\'t possible'],
            'star grouped' => ['SELECT * FROM Product GROUP BY name', 'isn\'t possible with GROUP BY'],
            'position' . ' out of range' => ['SELECT name FROM Product ORDER BY 2', 'ORDER BY 2 isn\'t a column'],
            'limit' => ['SELECT name FROM Product LIMIT -1', 'LIMIT needs a whole number'],
            'parameter' => ['SELECT name FROM Product WHERE name = :name', 'No value for the parameter :name'],
            'trailing' => ['SELECT name FROM Product name name', 'Unexpected "name" after the end'],
        ];
    }

    #[DataProvider('errors')]
    public function testErrors(string $query, string $message): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage($message);
        $this->oql->query($query);
    }
}
