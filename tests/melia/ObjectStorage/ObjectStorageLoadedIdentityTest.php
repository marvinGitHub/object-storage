<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Transaction;

/**
 * An object without AwareInterface doesn't carry its UUID: storing the instance returned by load() must still
 * update that object, not store a copy under a new UUID (and leave the original unchanged).
 */
final class ObjectStorageLoadedIdentityTest extends TestCase
{
    private function storeInOtherInstance(): string
    {
        $object = new \ArrayObject(['name' => 'a']);
        return (new ObjectStorage($this->storage->getStorageDir()))->store($object);
    }

    public function testStoringALoadedObjectUpdatesIt(): void
    {
        $uuid = $this->storeInOtherInstance();

        $loaded = $this->storage->load($uuid);
        $loaded['name'] = 'b';

        $this->assertSame($uuid, $this->storage->getUuidOf($loaded));
        $this->assertSame($uuid, $this->storage->store($loaded));
        $this->assertSame(['name' => 'b'], (new ObjectStorage($this->storage->getStorageDir()))->load($uuid)->getArrayCopy());
        $this->assertCount(1, iterator_to_array($this->storage->list(\ArrayObject::class), false));
    }

    public function testStoringALoadedObjectInATransactionUpdatesIt(): void
    {
        $uuid = $this->storeInOtherInstance();

        $loaded = $this->storage->load($uuid);
        $loaded['name'] = 'c';

        $transaction = new Transaction($this->storage);
        $transaction->begin();
        $this->assertSame($uuid, $transaction->store($loaded));
        $transaction->commit();

        $this->assertSame(['name' => 'c'], (new ObjectStorage($this->storage->getStorageDir()))->load($uuid)->getArrayCopy());
        $this->assertCount(1, iterator_to_array($this->storage->list(\ArrayObject::class), false));
    }

    public function testUnknownInstanceHasNoUuid(): void
    {
        $this->assertNull($this->storage->getUuidOf(new \ArrayObject()));
    }
}
