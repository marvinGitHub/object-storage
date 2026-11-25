<?php

namespace Tests\melia\ObjectStorage\Search\Index;

use melia\ObjectStorage\Search\Exception\SearchException;
use melia\ObjectStorage\Search\Index\UuidSets;
use melia\ObjectStorage\Util\UuidTrie;
use Tests\melia\ObjectStorage\TestCase;

class UuidSetsTest extends TestCase
{
    private const string A = '00000000-0000-4000-8000-00000000000a';
    private const string B = '00000000-0000-4000-8000-00000000000b';
    private const string C = '00000000-0000-4000-8000-00000000000c';

    public function testSetsAreKeptByKindAndTakenInOrder(): void
    {
        $sets = new UuidSets($this->storage, 'products');

        $sets->add(UuidSets::PENDING, [self::C, self::A]);
        $sets->add(UuidSets::PENDING, [self::B, self::A]);
        $sets->add(UuidSets::REFERENCED, [self::C]);

        $this->assertSame(3, $sets->count(UuidSets::PENDING), 'a UUID added twice is kept once');
        $this->assertSame(1, $sets->count(UuidSets::REFERENCED));
        $this->assertSame(0, $sets->count(UuidSets::BUILD), 'a set never stored is empty');

        $this->assertSame([self::A, self::B], $sets->take(UuidSets::PENDING, 2));
        $this->assertSame([self::C], $sets->read(UuidSets::PENDING)->first(10), 'taken UUIDs are removed');
        $this->assertFalse($this->storage->getLockAdapter()->isLockedByThisProcess(UuidSets::uuid('products', UuidSets::PENDING)), 'the lock of the set is released');
    }

    public function testSetsAreObjectsOfTheStorageNamedAfterTheIndex(): void
    {
        $products = new UuidSets($this->storage, 'products');
        $users = new UuidSets($this->storage, 'users');

        $trie = new UuidTrie();
        $trie->add(self::A, self::B);
        $products->replace(UuidSets::BUILD, $trie);

        $this->assertTrue($this->storage->exists(UuidSets::uuid('products', UuidSets::BUILD)));
        $this->assertSame(0, $users->count(UuidSets::BUILD), 'sets of another index are others');
        $this->assertNotSame(UuidSets::uuid('products', UuidSets::BUILD), UuidSets::uuid('products', UuidSets::REPLAY));

        $products->add(UuidSets::PENDING, [self::C]);
        $products->deleteAll();
        foreach (UuidSets::KINDS as $kind) {
            $this->assertFalse($this->storage->exists(UuidSets::uuid('products', $kind)), $kind);
        }
    }

    public function testAnotherObjectUnderTheUuidOfASetFails(): void
    {
        $sets = new UuidSets($this->storage, 'products');
        $this->storage->store(new \stdClass(), UuidSets::uuid('products', UuidSets::PENDING));

        try {
            $sets->add(UuidSets::PENDING, [self::A]);
            $this->fail('expected a SearchException');
        } catch (SearchException $e) {
            $this->assertStringContainsString('pending UUIDs of search index "products" are invalid', $e->getMessage());
        }
        $this->assertFalse($this->storage->getLockAdapter()->isLockedByThisProcess(UuidSets::uuid('products', UuidSets::PENDING)), 'the lock is released after a failure');
        $this->assertSame(0, $sets->count(UuidSets::PENDING), 'read as empty');
    }
}
