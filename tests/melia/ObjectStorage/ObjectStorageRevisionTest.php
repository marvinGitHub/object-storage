<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Event\Context\ObjectPersistenceContext;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\ObjectSavingFailureException;
use melia\ObjectStorage\Exception\RevisionConflictException;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\ObjectStorage;

class ObjectStorageRevisionTest extends TestCase
{
    /**
     * A second storage on the same directory, standing in for another process.
     */
    private function createSecondStorage(): ObjectStorage
    {
        return new ObjectStorage($this->storage->getStorageDir());
    }

    public function testRevisionIsIncrementedOnlyWhenDataChanges(): void
    {
        $object = new ChildObject('Child', 1);
        $uuid = $this->storage->store($object);
        $this->assertSame(1, $this->storage->getRevision($uuid));

        $this->storage->store($object);
        $this->assertSame(1, $this->storage->getRevision($uuid), 'unchanged data is not written, the revision stays');

        $object->value = 2;
        $this->storage->store($object);
        $this->assertSame(2, $this->storage->getRevision($uuid));

        $this->storage->setLifetime($uuid, 3600);
        $this->assertSame(2, $this->storage->getRevision($uuid), 'a lifetime change doesn\'t change the data');

        $this->storage->clearCache();
        $this->assertSame(2, $this->storage->getRevision($uuid));
    }

    public function testObjectSavedContextProvidesRevisions(): void
    {
        $contexts = [];
        $this->storage->getEventDispatcher()->addListener(Events::OBJECT_SAVED, function (ObjectPersistenceContext $context) use (&$contexts) {
            $contexts[] = [$context->getRevision(), $context->getPreviousRevision()];
        });

        $object = new ChildObject('Child', 1);
        $this->storage->store($object);

        $this->storage->store($object); // unchanged: no OBJECT_SAVED

        $object->value = 2;
        $this->storage->store($object);

        $this->assertSame([[1, null], [2, 1]], $contexts);
    }

    public function testOtherPersistenceEventsHaveNoRevision(): void
    {
        $revisions = [];
        foreach ([Events::BEFORE_STORE, Events::BEFORE_UPDATE, Events::AFTER_UPDATE] as $event) {
            $this->storage->getEventDispatcher()->addListener($event, function (ObjectPersistenceContext $context) use (&$revisions) {
                $revisions[] = $context->getRevision();
            });
        }

        $object = new ChildObject('Child', 1);
        $this->storage->store($object);
        $object->value = 2;
        $this->storage->store($object);

        $this->assertNotEmpty($revisions);
        $this->assertSame([null], array_values(array_unique($revisions, SORT_REGULAR)));
    }

    public function testRevisionIsNullForUnknownObjects(): void
    {
        $this->assertNull($this->storage->getRevision('00000000-0000-4000-8000-000000000000'));
    }

    public function testMetadataStoresFormatVersionAndRevision(): void
    {
        $uuid = $this->storage->store(new ChildObject('Child', 1));

        $metadata = json_decode(file_get_contents($this->storage->getFilePathMetadata($uuid)), true);

        $this->assertSame(Metadata::VERSION, $metadata['version']);
        $this->assertSame(1, $metadata['revision']);
    }

    public function testMetadataWithoutRevisionIsReadAsRevisionZero(): void
    {
        $object = new ChildObject('Child', 1);
        $uuid = $this->storage->store($object);

        $file = $this->storage->getFilePathMetadata($uuid);
        $metadata = json_decode(file_get_contents($file), true);
        unset($metadata['revision']);
        $metadata['version'] = 1;
        file_put_contents($file, json_encode($metadata));
        $this->storage->clearCache();

        $this->assertSame(0, $this->storage->getRevision($uuid));

        $this->storage->getStrategy()->enableOptimisticLocking();
        $loaded = $this->storage->load($uuid);
        $loaded->value = 2;
        $this->storage->store($loaded);

        $this->assertSame(1, $this->storage->getRevision($uuid));
    }

    public function testConcurrentUpdateWinsWithoutOptimisticLocking(): void
    {
        $uuid = $this->storage->store(new ChildObject('Child', 1));
        $other = $this->createSecondStorage();

        $mine = $this->storage->load($uuid);
        $theirs = $other->load($uuid);

        $theirs->value = 2;
        $other->store($theirs);

        $mine->value = 3;
        $this->storage->store($mine);

        $this->storage->clearCache();
        $this->assertSame(3, $this->storage->load($uuid)->value);
        $this->assertSame(3, $this->storage->getRevision($uuid));
    }

    public function testConcurrentUpdateFailsWithOptimisticLocking(): void
    {
        $this->storage->getStrategy()->enableOptimisticLocking();

        $uuid = $this->storage->store(new ChildObject('Child', 1));
        $other = $this->createSecondStorage();

        $conflicts = 0;
        $this->storage->getEventDispatcher()->addListener(Events::REVISION_CONFLICT, function () use (&$conflicts) {
            $conflicts++;
        });

        $mine = $this->storage->load($uuid);
        $theirs = $other->load($uuid);

        $theirs->value = 2;
        $other->store($theirs);

        $mine->value = 3;
        try {
            $this->storage->store($mine);
            $this->fail('RevisionConflictException expected');
        } catch (RevisionConflictException $e) {
            $this->assertInstanceOf(ObjectSavingFailureException::class, $e);
        }

        $this->assertSame(1, $conflicts);
        $this->assertFalse($this->storage->getLockAdapter()->isLockedByThisProcess($uuid));

        // the other update is kept
        $this->storage->clearCache();
        $this->assertSame(2, $this->storage->load($uuid)->value);
        $this->assertSame(2, $this->storage->getRevision($uuid));
    }

    public function testStoreSucceedsAfterReloading(): void
    {
        $this->storage->getStrategy()->enableOptimisticLocking();

        $uuid = $this->storage->store(new ChildObject('Child', 1));
        $other = $this->createSecondStorage();

        $mine = $this->storage->load($uuid);
        $theirs = $other->load($uuid);
        $theirs->value = 2;
        $other->store($theirs);

        // an exclusive load reads the stored state, so the update builds on the current revision
        $mine = $this->storage->load($uuid, true);
        $mine->value = 3;
        $this->storage->store($mine);

        $this->assertSame(3, $this->storage->getRevision($uuid));
    }

    public function testOwnSuccessiveStoresDontConflict(): void
    {
        $this->storage->getStrategy()->enableOptimisticLocking();

        $object = new ChildObject('Child', 1);
        $uuid = $this->storage->store($object);

        for ($i = 2; $i <= 5; $i++) {
            $object->value = $i;
            $this->storage->store($object);
        }

        $this->assertSame(5, $this->storage->getRevision($uuid));
    }

    public function testObjectDeletedInTheMeantimeIsCreatedAgain(): void
    {
        $this->storage->getStrategy()->enableOptimisticLocking();

        $uuid = $this->storage->store(new ChildObject('Child', 1));
        $other = $this->createSecondStorage();

        $mine = $this->storage->load($uuid);
        $other->delete($uuid);

        $mine->value = 2;
        $this->storage->store($mine, $uuid);

        $this->assertSame(1, $this->storage->getRevision($uuid));
    }

    public function testConflictOfReferencedObjectIsReported(): void
    {
        $this->storage->getStrategy()->enableOptimisticLocking();
        $this->storage->getStrategy()->setPolicyChildPersistence(\melia\ObjectStorage\Strategy\Policy\ChildPersistence::ALWAYS);

        $child = new ChildObject('Child', 1);
        $parent = new ParentObject('Parent', $child);
        $this->storage->store($parent);
        $childUuid = $child->getUUID();

        $other = $this->createSecondStorage();
        $theirs = $other->load($childUuid);
        $theirs->value = 2;
        $other->store($theirs);

        $child->value = 3;
        $this->expectException(RevisionConflictException::class);
        $this->storage->store($parent);
    }
}
