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

    /**
     * A user with an address (objects without AwareInterface), stored by another instance.
     *
     * @return array{string, string} The UUIDs of the user and the address.
     */
    private function storeUserWithAddress(): array
    {
        $address = new \stdClass();
        $address->city = 'Berlin';
        $user = new \stdClass();
        $user->name = 'Kim';
        $user->address = $address;
        $other = new ObjectStorage($this->storage->getStorageDir());
        $uuid = $other->store($user);
        return [$uuid, (string)$other->getUuidOf($address)];
    }

    public function testStoringAnObjectWithALoadedReferenceWritesNothingIfUnchanged(): void
    {
        [$uuid, $addressUuid] = $this->storeUserWithAddress();

        $user = $this->storage->load($uuid);
        $this->assertSame('Berlin', $user->address->city);

        // the reference replaced itself by the loaded object
        $this->assertInstanceOf(\stdClass::class, $user->address);
        $this->assertSame($addressUuid, $this->storage->getUuidOf($user->address), 'known by its UUID');
        $this->assertSame($uuid, $this->storage->store($user));

        // no copy of the address, and the user still refers to it
        $this->assertCount(2, iterator_to_array($this->storage->list(\stdClass::class), false));
        $this->assertSame(1, $this->storage->getRevision($uuid), 'the user is not written again');
        $this->assertSame(1, $this->storage->getRevision($addressUuid));
        $this->assertSame('Berlin', (new ObjectStorage($this->storage->getStorageDir()))->load($uuid)->address->city);
    }

    public function testAChangedLoadedReferenceIsWrittenAlongUnderItsUuid(): void
    {
        [$uuid, $addressUuid] = $this->storeUserWithAddress();

        // with the default policy (IF_NOT_EXIST): a loaded reference is written along if it changed
        $user = $this->storage->load($uuid);
        $user->address->city = 'Hamburg';
        $this->storage->store($user);

        $this->assertCount(2, iterator_to_array($this->storage->list(\stdClass::class), false));
        $this->assertSame('Hamburg', (new ObjectStorage($this->storage->getStorageDir()))->load($addressUuid)->city);
        $this->assertSame(1, $this->storage->getRevision($uuid), 'the user still refers to the same address');
    }

    public function testAChangedLoadedReferenceCanBeStoredByItself(): void
    {
        [$uuid, $addressUuid] = $this->storeUserWithAddress();

        $user = $this->storage->load($uuid);
        $address = $user->address->getObject();
        $address->city = 'Köln';

        $this->assertSame($addressUuid, $this->storage->store($address));
        $this->assertSame('Köln', (new ObjectStorage($this->storage->getStorageDir()))->load($addressUuid)->city);
        $this->assertCount(2, iterator_to_array($this->storage->list(\stdClass::class), false));
    }

    public function testAnUnloadedReferenceIsNotWrittenAlong(): void
    {
        [$uuid, $addressUuid] = $this->storeUserWithAddress();

        $user = $this->storage->load($uuid);
        $user->name = 'Alex';
        $this->storage->store($user);

        $this->assertSame(2, $this->storage->getRevision($uuid));
        $this->assertSame(1, $this->storage->getRevision($addressUuid), 'never loaded, so never written');
    }
}
