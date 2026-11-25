<?php

namespace Tests\melia\ObjectStorage\Storage;

use melia\ObjectStorage\Storage\ObjectSession;
use PHPUnit\Framework\TestCase;

class ObjectSessionTest extends TestCase
{
    private const string UUID = 'ab12cd34-0000-4000-8000-000000000001';
    private const string OTHER = 'ab12cd34-0000-4000-8000-000000000002';

    public function testTheUuidsOfObjects(): void
    {
        $session = new ObjectSession();
        $object = new \stdClass();
        $this->assertNull($session->uuidOf($object));

        $session->rememberIfUnknown($object, self::UUID);
        $session->rememberIfUnknown($object, self::OTHER);
        $this->assertSame(self::UUID, $session->uuidOf($object));

        $session->remember($object, self::OTHER);
        $this->assertSame(self::OTHER, $session->uuidOf($object));
    }

    public function testLoadedObjectsAreRecordedUntilCleared(): void
    {
        $session = new ObjectSession();
        $loaded = new \stdClass();
        $stored = new \stdClass();
        $session->recordLoaded($loaded);
        $session->remember($stored, self::UUID);

        $this->assertTrue($session->wasLoaded($loaded));
        $this->assertFalse($session->wasLoaded($stored), 'known, but not loaded');

        $session->clear();
        $this->assertFalse($session->wasLoaded($loaded));
    }

    public function testObjectsAreHeldWeakly(): void
    {
        $session = new ObjectSession();
        $object = new \stdClass();
        $session->remember($object, self::UUID);
        $session->recordRevision($object, 3);

        $this->assertSame(3, $session->revisionOf($object));
        $reference = \WeakReference::create($object);
        unset($object);
        $this->assertNull($reference->get(), 'the session doesn\'t keep objects alive');
    }

    public function testObjectsBeingStoredAndClearing(): void
    {
        $session = new ObjectSession();
        $object = new \stdClass();
        $session->remember($object, self::UUID);
        $session->recordRevision($object, 1);

        $session->startStoring($object);
        $this->assertTrue($session->isBeingStored($object));
        $session->stopStoring($object);
        $this->assertFalse($session->isBeingStored($object));

        $session->clear();
        $this->assertNull($session->uuidOf($object));
        $this->assertNull($session->revisionOf($object));
    }
}
