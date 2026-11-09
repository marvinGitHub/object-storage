<?php

namespace Tests\melia\ObjectStorage\Query;

use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Query\Exception\ScriptException;
use melia\ObjectStorage\Query\Oql;
use melia\ObjectStorage\Strategy\Policy\ChildDeletion;
use melia\ObjectStorage\TransactionRecovery;
use melia\ObjectStorage\Unique\Definition as UniqueDefinition;
use melia\ObjectStorage\Unique\Manager as UniqueManager;
use Tests\melia\ObjectStorage\Search\Category;
use Tests\melia\ObjectStorage\Search\Product;
use Tests\melia\ObjectStorage\TestCase;

/**
 * START TRANSACTION, COMMIT, ROLLBACK; atomic statements; scripts and previews.
 */
class OqlTransactionTest extends TestCase
{
    private Oql $oql;

    /** @var array<string, string> */
    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();
        $fruit = new Category('Fruit');
        $this->products['apple'] = $this->storage->store(new Product('Apple', 'red', 1.5, [], $fruit));
        $this->products['banana'] = $this->storage->store(new Product('Banana', 'yellow', 2, [], $fruit));
        $this->products['chair'] = $this->storage->store(new Product('Chair', 'oak', 49));
        $this->oql = new Oql($this->storage);
    }

    /** a value as another process reads it: from the files, without this connection */
    private function stored(string $query): mixed
    {
        $other = new ObjectStorage($this->storage->getStorageDir());
        return (new Oql($other))->query($query)->rows[0][0] ?? null;
    }

    private function prices(): array
    {
        $rows = (new Oql(new ObjectStorage($this->storage->getStorageDir())))->query('SELECT name, price FROM Product ORDER BY name')->rows;
        return array_column($rows, 1, 0);
    }

    private function transactionDirectories(): array
    {
        return glob($this->storage->getStorageDir() . DIRECTORY_SEPARATOR . TransactionRecovery::DIRECTORY . DIRECTORY_SEPARATOR . '*') ?: [];
    }

    // ---- transactions ----

    public function testRollbackUndoesTheChangesOfTheTransaction(): void
    {
        $result = $this->oql->query('START TRANSACTION');
        $this->assertSame('START TRANSACTION', $result->statement);
        $this->assertTrue($this->oql->inTransaction());

        $this->oql->query('UPDATE Product SET price = price * 10');
        $this->oql->query("INSERT INTO Product (name, price) VALUES ('Pear', 3)");
        $this->oql->query("DELETE FROM Product WHERE name = 'Chair'");

        // the connection sees its own changes
        $this->assertSame([['Apple', 15], ['Banana', 20], ['Pear', 3]], $this->oql->query('SELECT name, price FROM Product ORDER BY name')->rows);

        $this->assertSame('ROLLBACK', $this->oql->query('ROLLBACK')->statement);
        $this->assertFalse($this->oql->inTransaction());
        $this->assertSame(['Apple' => 1.5, 'Banana' => 2, 'Chair' => 49], $this->prices());
        $this->assertSame([], $this->transactionDirectories());
    }

    public function testCommitKeepsTheChanges(): void
    {
        $this->oql->query('BEGIN');
        $this->oql->query('UPDATE Product SET price = 5 WHERE name = :name', ['name' => 'Apple']);
        $this->assertSame('COMMIT', $this->oql->query('COMMIT WORK')->statement);

        $this->assertSame(5, $this->prices()['Apple']);
        $this->assertFalse($this->oql->inTransaction());
        $this->assertSame([], $this->transactionDirectories());
    }

    public function testTheObjectsChangedStayLockedUntilTheEnd(): void
    {
        $this->oql->query('START TRANSACTION');
        $this->oql->query("UPDATE Product SET price = 7 WHERE name = 'Apple'");

        $this->assertTrue($this->storage->getLockAdapter()->isLockedByThisProcess($this->products['apple']));
        $this->assertFalse($this->storage->getLockAdapter()->isLockedByThisProcess($this->products['banana']));

        $this->oql->query('COMMIT');
        $this->assertFalse($this->storage->getLockAdapter()->isLockedByThisProcess($this->products['apple']));
    }

    public function testCommitAndRollbackWithoutATransactionDoNothing(): void
    {
        $this->assertSame('COMMIT', $this->oql->query('COMMIT')->statement);
        $this->assertSame('ROLLBACK', $this->oql->query('rollback work')->statement);
        $this->assertFalse($this->oql->inTransaction());
    }

    public function testATransactionCantBeStartedTwice(): void
    {
        $this->oql->query('START TRANSACTION');
        try {
            $this->expectException(QueryException::class);
            $this->expectExceptionMessage('A transaction is already active');
            $this->oql->query('BEGIN');
        } finally {
            $this->oql->query('ROLLBACK');
        }
    }

    public function testOneTransactionPerStorageInstanceAnotherInstanceRunsItsOwn(): void
    {
        $this->oql->query('START TRANSACTION');
        try {
            try {
                (new Oql($this->storage))->query('START TRANSACTION');
                $this->fail('a second transaction on the same storage instance');
            } catch (QueryException $e) {
                $this->assertStringContainsString('Another transaction is active on this storage instance', $e->getMessage());
            }

            // another instance: its own transaction, on other objects
            $other = new Oql(new ObjectStorage($this->storage->getStorageDir()));
            $other->query('START TRANSACTION');
            $other->query("UPDATE Product SET price = 3 WHERE name = 'Chair'");
            $this->oql->query("UPDATE Product SET price = 4 WHERE name = 'Apple'");
            $other->query('COMMIT');
        } finally {
            $this->oql->query('ROLLBACK');
        }

        $this->assertSame(['Apple' => 1.5, 'Banana' => 2, 'Chair' => 3], $this->prices());
    }

    public function testWritesOfThePhpCodeThroughTheStorageArePartOfTheTransaction(): void
    {
        $this->oql->query('START TRANSACTION');
        $this->storage->store(new Product('Lamp', price: 20));
        $this->storage->delete($this->products['chair']);
        $this->oql->query('ROLLBACK');

        $this->assertSame(['Apple' => 1.5, 'Banana' => 2, 'Chair' => 49], $this->prices());
    }

    public function testObjectsDeletedAlongAreRestored(): void
    {
        $this->strategy()->setPolicyChildDeletion(ChildDeletion::ALWAYS);
        $categories = $this->stored('SELECT COUNT(*) FROM Category');

        $this->oql->query('START TRANSACTION');
        $this->oql->query("DELETE FROM Product WHERE name = 'Apple'");
        $this->assertSame(0, $this->oql->query("SELECT COUNT(*) FROM Category WHERE title = 'Fruit'")->rows[0][0], 'deleted along');
        $this->oql->query('ROLLBACK');

        $this->assertSame($categories, $this->stored('SELECT COUNT(*) FROM Category'));
        $this->assertSame('Fruit', $this->stored("SELECT category.title FROM Product WHERE name = 'Apple'"));
    }

    public function testAnOpenTransactionIsRolledBackWhenTheConnectionIsDropped(): void
    {
        $oql = new Oql($this->storage);
        $oql->query('START TRANSACTION');
        $oql->query('UPDATE Product SET price = 0');
        unset($oql);

        $this->assertSame(['Apple' => 1.5, 'Banana' => 2, 'Chair' => 49], $this->prices());
        $this->assertNull($this->storage->getActiveTransaction());
    }

    // ---- atomic statements ----

    public function testAFailingStatementWritesNothing(): void
    {
        $unique = new UniqueManager($this->storage);
        $unique->create(new UniqueDefinition('product-name', Product::class, ['name']));
        $unique->attach();
        try {
            try {
                // Apple is renamed first, then Banana violates the index
                $this->oql->query("UPDATE Product SET name = 'Fruit' WHERE category.title = 'Fruit'");
                $this->fail('two of the same name');
            } catch (QueryException $e) {
                $this->assertStringContainsString('the statement was rolled back, nothing was written', $e->getMessage());
            }
            $this->assertSame(0, $this->stored("SELECT COUNT(*) FROM Product WHERE name = 'Fruit'"));
            $this->assertSame([], $this->transactionDirectories());
        } finally {
            $unique->detach();
        }
    }

    public function testAFailingStatementInATransactionIsRolledBackAloneAndTheTransactionStaysOpen(): void
    {
        $unique = new UniqueManager($this->storage);
        $unique->create(new UniqueDefinition('product-name', Product::class, ['name']));
        $unique->attach();
        try {
            $this->oql->query('START TRANSACTION');
            $this->oql->query("UPDATE Product SET price = 100 WHERE name = 'Chair'");
            $this->oql->query("UPDATE Product SET price = 1 WHERE name = 'Apple'");
            try {
                $this->oql->query("UPDATE Product SET name = 'Fruit', price = 0 WHERE category.title = 'Fruit'");
                $this->fail('two of the same name');
            } catch (QueryException $e) {
                $this->assertStringContainsString('the statement was rolled back, the transaction is still open', $e->getMessage());
            }
            $this->assertTrue($this->oql->inTransaction());
            // the statements before it stay, the failed one is undone (Apple keeps the price of the statement before)
            $this->assertSame([['Apple', 1], ['Banana', 2], ['Chair', 100]], $this->oql->query('SELECT name, price FROM Product ORDER BY name')->rows);
            $this->oql->query('COMMIT');
        } finally {
            $unique->detach();
        }

        $this->assertSame(['Apple' => 1, 'Banana' => 2, 'Chair' => 100], $this->prices());
    }

    public function testAStatementFailingBeforeWritingKeepsItsMessage(): void
    {
        try {
            // checked before the first object is written: nothing to roll back
            $this->oql->query('INSERT INTO Product (name, nothing) VALUES (:n, 1)', ['n' => 'Pear']);
            $this->fail('Product has no property nothing');
        } catch (QueryException $e) {
            $this->assertStringContainsString('nothing', $e->getMessage());
            $this->assertStringNotContainsString('rolled back', $e->getMessage());
        }
        $this->assertSame([], $this->transactionDirectories());
    }

    public function testADryRunNeedsNoTransaction(): void
    {
        $result = $this->oql->query('UPDATE Product SET price = 0', dryRun: true);

        $this->assertSame(3, $result->affected());
        $this->assertSame([], $this->transactionDirectories());
    }

    // ---- scripts ----

    public function testAScriptRunsItsStatementsInOrder(): void
    {
        $script = $this->oql->script(<<<'OQL'
            -- a comment; not a separator
            START TRANSACTION;
            UPDATE Product SET description = 'a; b' WHERE name = :name;
            SELECT description FROM Product WHERE name = :name;
            COMMIT;
            OQL, ['name' => 'Apple']);

        $this->assertSame(['START TRANSACTION', 'UPDATE', null, 'COMMIT'], array_map(static fn($r) => $r->statement, $script->results));
        $this->assertSame([['a; b']], $script->results[2]->rows);
        $this->assertFalse($script->rolledBack);
        $this->assertSame('a; b', $this->stored("SELECT description FROM Product WHERE name = 'Apple'"));
    }

    public function testPositionalParametersAreNumberedAcrossTheScript(): void
    {
        $script = $this->oql->script('SELECT name FROM Product WHERE price = ?; SELECT name FROM Product WHERE price = ?', [49, 2]);

        $this->assertSame([['Chair']], $script->results[0]->rows);
        $this->assertSame([['Banana']], $script->results[1]->rows);
    }

    public function testAScriptLeavingATransactionOpenIsRolledBack(): void
    {
        $script = $this->oql->script('START TRANSACTION; UPDATE Product SET price = 0');

        $this->assertTrue($script->rolledBack);
        $this->assertFalse($this->oql->inTransaction());
        $this->assertSame(['Apple' => 1.5, 'Banana' => 2, 'Chair' => 49], $this->prices());
    }

    public function testAFailingStatementStopsTheScriptAndRollsBackItsTransaction(): void
    {
        $script = "START TRANSACTION;\nUPDATE Product SET price = 0;\nSELECT nothing FROM";
        try {
            $this->oql->script($script);
            $this->fail('the third statement is invalid');
        } catch (ScriptException $e) {
            $this->assertSame(2, $e->statement);
            $this->assertCount(2, $e->results);
            $this->assertTrue($e->rolledBack);
            $this->assertStringStartsWith('Statement 3: ', $e->getMessage());
            // the position and line are those in the script
            $this->assertStringContainsString('line 3', $e->getMessage());
            $this->assertSame(strlen($script), $e->position);
        }

        $this->assertSame(['Apple' => 1.5, 'Banana' => 2, 'Chair' => 49], $this->prices());
    }

    public function testAPreviewRollsEverythingBack(): void
    {
        $script = $this->oql->script(<<<'OQL'
            UPDATE Product SET price = 1;
            START TRANSACTION;
            DELETE FROM Product WHERE name = 'Chair';
            ROLLBACK;
            SELECT COUNT(*) FROM Product;
            START TRANSACTION;
            INSERT INTO Product (name, price) VALUES ('Pear', 3);
            COMMIT;
            SELECT SUM(price) FROM Product
            OQL, preview: true);

        $this->assertTrue($script->preview);
        $this->assertSame([[3]], $script->results[4]->rows, 'the ROLLBACK of the preview undid the DELETE');
        $this->assertSame([[6]], $script->results[8]->rows, 'the changes are seen until the end');
        $this->assertSame(['Apple' => 1.5, 'Banana' => 2, 'Chair' => 49], $this->prices());
        $this->assertSame([], $this->transactionDirectories());
    }

    public function testAPreviewCantRunInATransaction(): void
    {
        $this->oql->query('START TRANSACTION');
        try {
            $this->expectException(QueryException::class);
            $this->oql->script('SELECT name FROM Product', preview: true);
        } finally {
            $this->oql->query('ROLLBACK');
        }
    }

    public function testWritesDetectsEveryStatementOfAScript(): void
    {
        $this->assertFalse(Oql::writes('SELECT name FROM Product; EXPLAIN SELECT name FROM Product'));
        $this->assertTrue(Oql::writes('SELECT name FROM Product; DELETE FROM Product'));
        $this->assertTrue(Oql::writes('BEGIN'));
        $this->assertFalse(Oql::writes("SELECT 'x; DELETE FROM Product' FROM Product"));
        $this->assertFalse(Oql::writes('SELECT name FROM Product; SELECT name FROM'), 'an invalid statement writes nothing');
        $this->assertTrue(Oql::writes('SELECT name FROM; DELETE FROM Product'));
        $this->assertFalse(Oql::writes("SELECT 'open"));
    }

    public function testAScriptWithoutStatementsIsRefused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('No statement');
        $this->oql->script(" ; -- nothing\n;");
    }
}
