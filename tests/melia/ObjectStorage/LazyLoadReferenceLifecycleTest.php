<?php

namespace Tests\melia\ObjectStorage;

use DateTimeImmutable;
use melia\ObjectStorage\Exception\DanglingReferenceException;
use melia\ObjectStorage\LazyLoadReference;
use WeakReference;

final class LazyLoadReferenceLifecycleTest extends TestCase
{
    /**
     * Runs in a separate process: the leak only affected the first reference loaded in a process.
     *
     * @runInSeparateProcess
     */
    public function testLoadedReferenceAndRootAreGarbageCollected(): void
    {
        $parent = new LifecycleParent();
        $parent->child = new LifecycleChild();
        $uuid = $this->storage->store($parent);
        unset($parent);

        $this->storage->clearCache();
        $loaded = $this->storage->load($uuid);
        $this->assertInstanceOf(LazyLoadReference::class, $loaded->child);
        $this->assertSame('child', $loaded->child->name);

        $weakRoot = WeakReference::create($loaded);
        unset($loaded);
        $this->storage->clearCache();
        gc_collect_cycles();

        $this->assertNull($weakRoot->get(), 'the root object must not be kept alive after its reference was loaded');
    }

    public function testExpiredReferenceIsReportedAsExpired(): void
    {
        $parent = new LifecycleParent();
        $parent->child = new LifecycleChild();
        $uuid = $this->storage->store($parent);

        $this->storage->clearCache();
        $loaded = $this->storage->load($uuid);
        $this->storage->setExpiration($loaded->child->getUUID(), new DateTimeImmutable('-1 minute'));
        $this->storage->clearCache();

        $this->expectException(DanglingReferenceException::class);
        $this->expectExceptionMessageMatches('/is expired/');

        $unused = $loaded->child->name;
    }
}

class LifecycleChild
{
    public string $name = 'child';
}

class LifecycleParent
{
    public mixed $child = null;
}
