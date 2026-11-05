<?php

namespace Tests\melia\ObjectStorage\Unique;

use melia\ObjectStorage\Exception\ConstraintViolationException;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Transfer\ObjectTransfer;
use melia\ObjectStorage\Unique\Definition;
use melia\ObjectStorage\Unique\Exception\DuplicateValuesException;
use melia\ObjectStorage\Unique\Exception\InvalidUniqueIndexException;
use melia\ObjectStorage\Unique\Exception\UniqueConstraintViolationException;
use melia\ObjectStorage\Unique\Exception\UniqueIndexAlreadyExistsException;
use melia\ObjectStorage\Unique\Manager;
use Tests\melia\ObjectStorage\Concurrency\Account;
use Tests\melia\ObjectStorage\Search\Category;
use Tests\melia\ObjectStorage\Search\Product;
use Tests\melia\ObjectStorage\TestCase;

/**
 * Unique indexes: values no two objects of a class may share, and finding objects by them.
 */
final class UniqueIndexTest extends TestCase
{
    private Manager $unique;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unique = new Manager($this->storage);
        $this->unique->create(new Definition('account-email', Account::class, 'email', caseInsensitive: true));
        $this->unique->attach();
    }

    private function storedCount(string $className): int
    {
        return iterator_count($this->storage->list($className));
    }

    public function testASecondObjectWithTheValueIsNotWritten(): void
    {
        $ada = $this->storage->store(new Account('ada@example.com'));

        try {
            $this->storage->store(new Account(' ADA@Example.com '));
            $this->fail('a duplicate was stored');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertSame('account-email', $e->getIndexName());
            $this->assertSame(['email' => ' ADA@Example.com '], $e->getValues());
            $this->assertSame($ada, $e->getOwner());
            $this->assertInstanceOf(ConstraintViolationException::class, $e);
        }

        $this->assertSame(1, $this->storedCount(Account::class), 'nothing written, no stub');
        $this->assertSame($ada, $this->unique->findOwner('account-email', 'Ada@Example.com'));
        $this->assertSame('ada@example.com', $this->unique->find('account-email', 'ada@example.com')->email);
        $this->assertNull($this->unique->find('account-email', 'bob@example.com'));
    }

    public function testChangingTheValueReleasesTheOldOne(): void
    {
        $account = new Account('ada@example.com');
        $uuid = $this->storage->store($account);
        $this->storage->store($account, $uuid); // unchanged: fine

        $account->email = 'ada@work.example';
        $this->storage->store($account, $uuid);

        $this->assertNull($this->unique->findOwner('account-email', 'ada@example.com'));
        $this->assertSame($uuid, $this->unique->findOwner('account-email', 'ada@work.example'));
        $this->storage->store(new Account('ada@example.com')); // free again
        $this->assertSame(2, $this->storedCount(Account::class));
    }

    public function testDeletingReleasesTheValue(): void
    {
        $uuid = $this->storage->store(new Account('ada@example.com'));
        $this->storage->delete($uuid);

        $this->assertNull($this->unique->findOwner('account-email', 'ada@example.com'));
        $this->storage->store(new Account('ada@example.com'));
        $this->assertSame(1, $this->storedCount(Account::class));
    }

    public function testCombinationsAndNullValues(): void
    {
        $this->unique->create(new Definition('product-name-price', Product::class, ['name', 'price']));
        $this->storage->store(new Product('Apple', price: 1.5));
        $this->storage->store(new Product('Apple', price: 2));      // another combination
        $this->storage->store(new Product('Pear', price: 1.5));
        $this->storage->store(new Product('Apple', price: null));   // null: no key, as in SQL
        $this->storage->store(new Product('Apple', price: null));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->storage->store(new Product('Apple', price: '1.50')); // numbers and numeric strings alike
    }

    public function testReferencesAreComparedByUuid(): void
    {
        $fruit = new Category('Fruit');
        $this->storage->store($fruit);
        $this->unique->create(new Definition('product-in-category', Product::class, ['category', 'name']));

        $this->storage->store(new Product('Apple', category: $fruit));
        $this->storage->store(new Product('Apple', category: new Category('Other fruit')));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->storage->store(new Product('Apple', category: $fruit));
    }

    public function testAViolationOfAReferencedObjectComesThrough(): void
    {
        $this->unique->create(new Definition('category-title', Category::class, 'title'));
        $this->storage->store(new Category('Fruit'));

        try {
            $this->storage->store(new Product('Apple', category: new Category('Fruit')));
            $this->fail('the duplicate category was stored');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertSame('category-title', $e->getIndexName());
        }
        $this->assertSame(1, $this->storedCount(Category::class));
    }

    public function testCreatingAnIndexOverDuplicatesFails(): void
    {
        $this->storage->store(new Product('Apple'));
        $first = $this->storage->store(new Product('Pear'));
        $second = $this->storage->store(new Product('Pear'));

        try {
            $this->unique->create(new Definition('product-name', Product::class, 'name'));
            $this->fail('created over duplicates');
        } catch (DuplicateValuesException $e) {
            $this->assertSame(['["s:Pear"]'], array_keys($e->getDuplicates()));
            $this->assertEqualsCanonicalizing([$first, $second], $e->getDuplicates()['["s:Pear"]']);
        }
        $this->assertFalse($this->unique->has('product-name'), 'not created');

        $this->expectException(UniqueIndexAlreadyExistsException::class);
        $this->unique->create(new Definition('account-email', Account::class, 'email'));
    }

    public function testAPathWithoutSingleValuesIsRejected(): void
    {
        $this->storage->store(new Product('Apple', tags: ['red', 'sweet']));

        try {
            $this->unique->create(new Definition('product-tags', Product::class, 'tags'));
            $this->fail('created on a list');
        } catch (InvalidUniqueIndexException $e) {
            $this->assertStringContainsString('lists can\'t be unique', $e->getMessage());
        }
        $this->assertFalse($this->unique->has('product-tags'));

        $this->unique->create(new Definition('category-title', Category::class, 'title')); // no objects yet: fine
        $this->assertTrue($this->unique->has('category-title'));
    }

    public function testRebuildAfterWritesWithoutTheIndex(): void
    {
        $this->unique->detach();
        $uuid = $this->storage->store(new Account('ada@example.com'));
        $this->assertNull($this->unique->findOwner('account-email', 'ada@example.com'), 'written without the index');

        $this->assertSame(1, $this->unique->rebuild('account-email'));
        $this->assertSame($uuid, $this->unique->findOwner('account-email', 'ada@example.com'));
    }

    public function testAClaimOfAnObjectThatLostTheValueIsTakenOver(): void
    {
        $account = new Account('ada@example.com');
        $uuid = $this->storage->store($account);

        // changed without the index, e.g. a crash between writing the object and updating the claims
        $this->unique->detach();
        $account->email = 'ada@work.example';
        $this->storage->store($account, $uuid);
        $this->unique->attach();

        $this->assertNull($this->unique->findOwner('account-email', 'ada@example.com'), 'not trusted');
        $other = $this->storage->store(new Account('ada@example.com'));
        $this->assertSame($other, $this->unique->findOwner('account-email', 'ada@example.com'));
    }

    public function testCopiesIntoAStorageAreChecked(): void
    {
        $target = $this->createStorage();
        $targetUnique = new Manager($target);
        $targetUnique->create(new Definition('account-email', Account::class, 'email', caseInsensitive: true));
        $targetUnique->attach();
        $target->store(new Account('ADA@example.com'));

        $uuid = $this->storage->store(new Account('ada@example.com'));
        $result = (new ObjectTransfer($this->storage, $target))->copy([$uuid]);

        $this->assertStringContainsString('Unique index "account-email"', $result->failed[$uuid]);
        $this->assertSame(1, iterator_count($target->list(Account::class)));
    }

    public function testDroppingAnIndexAllowsDuplicates(): void
    {
        $this->storage->store(new Account('ada@example.com'));
        $this->assertTrue($this->unique->drop('account-email'));
        $this->assertFalse($this->unique->drop('account-email'));

        $this->storage->store(new Account('ada@example.com'));
        $this->assertSame(2, $this->storedCount(Account::class));
        $this->assertSame([], iterator_to_array($this->unique->getInternalStorage()->list(), false), 'no claims left');
    }

    public function testOtherProcessesSeeTheIndex(): void
    {
        $this->storage->store(new Account('ada@example.com'));

        // another process: its own storage instance and manager
        $storage = new ObjectStorage($this->storage->getStorageDir());
        (new Manager($storage))->attach();

        $this->expectException(UniqueConstraintViolationException::class);
        $storage->store(new Account('ada@example.com'));
    }

    public function testInvalidDefinitions(): void
    {
        foreach ([
            static fn() => new Definition('Email', Account::class, 'email'),
            static fn() => new Definition('email', Account::class, []),
            static fn() => new Definition('email', Account::class, ['email', 'email']),
            static fn() => new Definition('email', Account::class, 'address..email'),
            static fn() => new Definition('email', '', 'email'),
        ] as $i => $create) {
            try {
                $create();
                $this->fail("definition $i was accepted");
            } catch (InvalidUniqueIndexException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
