<?php

namespace Tests\melia\ObjectStorage\Query;

use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Query\Oql;
use Tests\melia\ObjectStorage\Search\Category;
use Tests\melia\ObjectStorage\Search\Product;
use Tests\melia\ObjectStorage\TestCase;

/**
 * Statements that once changed or deleted more objects than they should have.
 */
class OqlDataLossTest extends TestCase
{
    private Oql $oql;
    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();
        $fruit = new Category('Fruit');
        $this->products['apple'] = $this->storage->store(new Product('Apple', 'red', 1.5, ['red'], $fruit));
        $this->products['banana'] = $this->storage->store(new Product('Banana', 'yellow', 2, [], $fruit));
        $this->products['chair'] = $this->storage->store(new Product('Chair', 'oak', 49));
        $this->oql = new Oql($this->storage);
    }

    private function names(): array
    {
        return array_column($this->oql->query('SELECT name FROM Product ORDER BY name')->rows, 0);
    }

    /**
     * A referenced object that exists but can't be read was taken as NULL: `category.title IS NULL` held for the
     * products referencing it, and they were deleted.
     */
    public function testUnreadableReferencedObjectFailsTheStatement(): void
    {
        $fruit = $this->oql->query("SELECT UUID FROM Category WHERE title = 'Fruit'")->rows[0][0];
        // damaged: the data doesn't match its checksum anymore
        file_put_contents($this->storage->getFilePathData($fruit), '{"title":"Fruit!"}');
        $this->storage->clearCache();

        try {
            $this->oql->query('DELETE FROM Product WHERE category.title IS NULL');
            $this->fail('A referenced object that can\'t be read must fail the statement');
        } catch (QueryException $e) {
            $this->assertStringContainsString($fruit, $e->getMessage());
        }

        $this->assertTrue($this->storage->exists($this->products['apple']));
        $this->assertTrue($this->storage->exists($this->products['banana']));
        $this->assertTrue($this->storage->exists($this->products['chair']));
    }

    /**
     * "--" glued to a value started a comment that swallowed the WHERE: every product was changed.
     */
    public function testDoubleMinusAfterAValueIsRejected(): void
    {
        foreach (["UPDATE Product SET price = 50--5 WHERE name = 'Apple'", "UPDATE Product SET price = price-" . -5 . " WHERE name = 'Apple'"] as $statement) {
            try {
                $this->oql->query($statement);
                $this->fail('Must be rejected: ' . $statement);
            } catch (QueryException $e) {
                $this->assertStringContainsString('"--"', $e->getMessage());
            }
        }
        $this->assertSame([[1.5], [2], [49]], $this->oql->query('SELECT price FROM Product ORDER BY name')->rows);
    }

    public function testCommentsStillWork(): void
    {
        $this->assertSame([['Apple']], $this->oql->query("-- at the very start\nSELECT name FROM Product WHERE name = 'Apple'")->rows);
        $this->oql->query("UPDATE Product SET price = 50 - -5 -- a comment\nWHERE name = 'Apple'");
        $this->oql->script("UPDATE Product SET price = 1 WHERE name = 'Banana';-- after the semicolon\n/* block */-- after a block comment\n");

        $this->assertSame([[55], [1], [49]], $this->oql->query('SELECT price FROM Product ORDER BY name')->rows);
    }

    /**
     * A semicolon cut off the WHERE: the first statement deleted everything before the second failed to parse.
     */
    public function testScriptIsReadCompletelyBeforeRunning(): void
    {
        try {
            $this->oql->script("DELETE FROM Product;\nWHERE name = 'Apple'");
            $this->fail('The script must fail');
        } catch (\melia\ObjectStorage\Query\Exception\ScriptException $e) {
            $this->assertSame(1, $e->statement);
            $this->assertSame([], $e->results);
        }

        $this->assertSame(['Apple', 'Banana', 'Chair'], $this->names());
    }

    /**
     * LIKE needing more backtracking than PCRE allows was false, so NOT LIKE held for a matching text.
     */
    public function testLikeThatCannotBeEvaluatedFailsTheStatement(): void
    {
        $long = 'xxxxxx' . 'y' . str_repeat('x', 3000);
        $this->storage->store(new Product($long));
        $pattern = '%x%x%x%x%x%x%y%';

        try {
            $this->oql->query('DELETE FROM Product WHERE name NOT LIKE ?', [$pattern]);
        } catch (QueryException $e) {
            $this->assertStringContainsString('LIKE', $e->getMessage());
        }

        // either it could be evaluated (and the long name, which matches, was kept), or nothing was deleted
        $this->assertContains($long, $this->names());
    }

    public function testDryRunRollbackKeepsTheTransaction(): void
    {
        $this->oql->query('START TRANSACTION');
        $this->oql->query("UPDATE Product SET price = 7 WHERE name = 'Apple'");

        $this->oql->query('ROLLBACK', [], true);
        $this->oql->query('COMMIT', [], true);
        $this->assertTrue($this->oql->inTransaction(), 'a dry run must neither roll back nor commit');

        $this->oql->query('COMMIT');
        $this->assertSame([[7], [2], [49]], $this->oql->query('SELECT price FROM Product ORDER BY name')->rows);
    }

    public function testNotANumberIsNoParameter(): void
    {
        $this->expectException(QueryException::class);
        try {
            $this->oql->query('DELETE FROM Product WHERE price > ?', [NAN]);
        } finally {
            $this->assertSame(['Apple', 'Banana', 'Chair'], $this->names());
        }
    }

    public function testArithmeticWithoutAFiniteResultIsNull(): void
    {
        $this->oql->query('DELETE FROM Product WHERE price > 1e999 - 1e999');
        $this->assertSame(['Apple', 'Banana', 'Chair'], $this->names());
    }

    /**
     * A stale stub (left by an interrupted change of the class) names an object of another class.
     */
    public function testStaleStubDoesNotLetADeleteReachAnotherClass(): void
    {
        $fruit = $this->oql->query("SELECT UUID FROM Category WHERE title = 'Fruit'")->rows[0][0];
        $this->storage->stubIndex()->add(Product::class, $fruit);

        $this->oql->query('DELETE FROM Product');

        $this->assertTrue($this->storage->exists($fruit));
        $this->assertSame([], $this->names());
    }

    public function testDeletedReferencedObjectIsStillNull(): void
    {
        $fruit = $this->oql->query("SELECT UUID FROM Category WHERE title = 'Fruit'")->rows[0][0];
        $this->storage->delete($fruit);

        $this->oql->query('DELETE FROM Product WHERE category.title IS NULL');

        $this->assertSame([], $this->names());
    }
}
