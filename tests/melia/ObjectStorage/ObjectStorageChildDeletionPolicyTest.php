<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Exception\InvalidPolicyException;
use melia\ObjectStorage\Strategy\Policy\ChildDeletion;
use melia\ObjectStorage\Strategy\Standard;
use RuntimeException;
use stdClass;

class ObjectStorageChildDeletionPolicyTest extends TestCase
{
    public function testSetChildDeletionPolicyRejectsInvalidValue(): void
    {
        $this->expectException(InvalidPolicyException::class);
        $this->strategy()->setPolicyChildDeletion(999999);
    }

    public function testDefaultPolicyKeepsReferencedObjects(): void
    {
        $this->assertSame(ChildDeletion::NEVER, $this->storage->getStrategy()->getPolicyChildDeletion());

        $child = new ChildObject('Child', 1);
        $parentUuid = $this->storage->store(new ParentObject('Parent', $child));

        $this->storage->delete($parentUuid);

        $this->assertFalse($this->storage->exists($parentUuid));
        $this->assertTrue($this->storage->exists($child->getUUID()));
    }

    public function testPolicyAlwaysDeletesReferencedObjectsRecursively(): void
    {
        $this->strategy()->setPolicyChildDeletion(ChildDeletion::ALWAYS);

        $grandChild = new ChildObject('GrandChild', 1);
        $child = new ParentObject('Child', $grandChild);
        $parentUuid = $this->storage->store(new ParentObject('Parent', $child));

        $this->storage->clearCache();
        $this->storage->delete($parentUuid);

        $this->assertFalse($this->storage->exists($parentUuid));
        $this->assertFalse($this->storage->exists($child->getUUID()));
        $this->assertFalse($this->storage->exists($grandChild->getUUID()));
        $this->assertSame([], iterator_to_array($this->storage->list(ChildObject::class), false));
    }

    public function testPolicyAlwaysDeletesReferencedObjectsInNestedArrays(): void
    {
        $this->strategy()->setPolicyChildDeletion(ChildDeletion::ALWAYS);

        $order = new stdClass();
        $order->lines = ['a' => [new ChildObject('Line 1', 1)], 'b' => [[new ChildObject('Line 2', 2)]]];
        $orderUuid = $this->storage->store($order);

        $lineUuids = iterator_to_array($this->storage->list(ChildObject::class), false);
        $this->assertCount(2, $lineUuids);

        $this->storage->delete($orderUuid);

        foreach ($lineUuids as $lineUuid) {
            $this->assertFalse($this->storage->exists($lineUuid));
        }
    }

    public function testPolicyAlwaysHandlesCircularReferences(): void
    {
        $this->strategy()->setPolicyChildDeletion(ChildDeletion::ALWAYS);

        $a = new stdClass();
        $b = new stdClass();
        $a->other = $b;
        $b->other = $a;
        $a->self = $a;

        $uuidA = $this->storage->store($a);
        $uuids = iterator_to_array($this->storage->list(stdClass::class), false);
        $this->assertCount(2, $uuids);

        $this->storage->delete($uuidA);

        foreach ($uuids as $uuid) {
            $this->assertFalse($this->storage->exists($uuid));
        }
    }

    public function testPolicyAlwaysIgnoresReferencedObjectsDeletedBefore(): void
    {
        $this->strategy()->setPolicyChildDeletion(ChildDeletion::ALWAYS);

        $child = new ChildObject('Child', 1);
        $parentUuid = $this->storage->store(new ParentObject('Parent', $child));

        $this->strategy()->setPolicyChildDeletion(ChildDeletion::NEVER);
        $this->storage->delete($child->getUUID());
        $this->strategy()->setPolicyChildDeletion(ChildDeletion::ALWAYS);

        $this->storage->delete($parentUuid);

        $this->assertFalse($this->storage->exists($parentUuid));
    }

    public function testPolicyAlwaysDeletesCorruptedObjectWithoutReferencedObjects(): void
    {
        $this->strategy()->setPolicyChildDeletion(ChildDeletion::ALWAYS);
        $this->strategy()->disableChecksumValidation();

        $child = new ChildObject('Child', 1);
        $parentUuid = $this->storage->store(new ParentObject('Parent', $child));
        file_put_contents($this->storage->getFilePathData($parentUuid), '{corrupted');

        $this->storage->delete($parentUuid);

        $this->assertFalse($this->storage->exists($parentUuid));
        $this->assertTrue($this->storage->exists($child->getUUID()));
    }

    public function testPolicyCallbackDeletesOnlySelectedReferencedObjects(): void
    {
        $strategy = new class extends Standard {
            public array $calls = [];

            public function shouldDeleteChild(string $parentUuid, string $parentClassName, string $childUuid, string $childClassName, array $path): bool
            {
                $this->calls[] = [$parentClassName, $childClassName, $path];
                return $path === ['owned', 0];
            }
        };
        $strategy->setPolicyChildDeletion(ChildDeletion::CALLBACK);
        $this->storage->setStrategy($strategy);

        $owned = new ChildObject('Owned', 1);
        $shared = new ChildObject('Shared', 2);
        $parent = new stdClass();
        $parent->owned = [$owned];
        $parent->shared = $shared;
        $parentUuid = $this->storage->store($parent);

        $this->storage->delete($parentUuid);

        $this->assertFalse($this->storage->exists($owned->getUUID()));
        $this->assertTrue($this->storage->exists($shared->getUUID()));
        $this->assertContains([stdClass::class, ChildObject::class, ['owned', 0]], $strategy->calls);
        $this->assertContains([stdClass::class, ChildObject::class, ['shared']], $strategy->calls);
    }

    public function testPolicyCallbackKeepsReferencedObjectWhenCallbackFails(): void
    {
        $strategy = new class extends Standard {
            public function shouldDeleteChild(string $parentUuid, string $parentClassName, string $childUuid, string $childClassName, array $path): bool
            {
                throw new RuntimeException('failure');
            }
        };
        $strategy->setPolicyChildDeletion(ChildDeletion::CALLBACK);
        $this->storage->setStrategy($strategy);

        $child = new ChildObject('Child', 1);
        $parentUuid = $this->storage->store(new ParentObject('Parent', $child));

        $this->storage->delete($parentUuid);

        $this->assertFalse($this->storage->exists($parentUuid));
        $this->assertTrue($this->storage->exists($child->getUUID()));
    }
}
