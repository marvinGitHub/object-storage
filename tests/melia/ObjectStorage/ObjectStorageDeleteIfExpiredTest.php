<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\ObjectStorage;

final class ObjectStorageDeleteIfExpiredTest extends TestCase
{
    public function testExpiredObjectIsDeleted(): void
    {
        $uuid = $this->storage->store(new ChildObject('child', 1));
        $this->storage->setLifetime($uuid, -1);

        $this->assertTrue($this->storage->deleteIfExpired($uuid));
        $this->assertFalse($this->storage->exists($uuid));
    }

    public function testObjectWithoutLifetimeIsKept(): void
    {
        $uuid = $this->storage->store(new ChildObject('child', 1));

        $this->assertFalse($this->storage->deleteIfExpired($uuid));
        $this->assertTrue($this->storage->exists($uuid));
    }

    /**
     * The purge found the object expired (cached metadata), then another process extended its lifetime.
     */
    public function testLifetimeExtendedByAnotherProcessIsRespected(): void
    {
        $uuid = $this->storage->store(new ChildObject('child', 1));
        $this->storage->setLifetime($uuid, -1);
        $this->assertTrue($this->storage->expired($uuid));

        (new ObjectStorage($this->storage->getStorageDir()))->setLifetime($uuid, 3600);

        $this->assertFalse($this->storage->deleteIfExpired($uuid));
        $this->assertTrue($this->storage->exists($uuid));
    }
}
