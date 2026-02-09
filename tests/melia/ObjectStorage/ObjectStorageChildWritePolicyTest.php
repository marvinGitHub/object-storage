<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Exception\InvalidChildWritePolicyException;
use melia\ObjectStorage\Strategy\StrategyInterface;

class ObjectStorageChildWritePolicyTest extends TestCase
{
    public function testSetChildWritePolicyRejectsInvalidValue(): void
    {
        $this->expectException(InvalidChildWritePolicyException::class);
        $this->storage->getStrategy()->setChildWritePolicy(999999);
    }

    public function testPolicyNeverDoesNotWriteChildWhenStoringParent(): void
    {
        // Arrange
        $this->storage->getStrategy()->setChildWritePolicy(StrategyInterface::POLICY_CHILD_WRITE_NEVER);

        $child = new ChildObject('Child', 10);
        $parent = new ParentObject('Parent', $child);

        // Initial store (depending on implementation, child may be stored here; we only care about the next store)
        $parentUuid = $this->storage->store($parent);
        $childUuid = $child->getUUID();

        $this->writerSpy->clearMethodCalls();

        // Act: modify child, then store only the parent
        $child->value = 11;
        $this->storage->store($parent, $parentUuid);

        // Assert: parent store must not cascade-write the child under POLICY_CHILD_WRITE_NEVER
        $parentCalls = count($this->writerSpy->getCallsForUuid($parentUuid));
        $childCalls = count($this->writerSpy->getCallsForUuid($childUuid));

        $this->assertGreaterThanOrEqual(0, $parentCalls, 'Parent may or may not be written depending on change detection');
        $this->assertSame(0, $childCalls, 'Child must NOT be written when policy is NEVER and parent is stored');
    }

    public function testPolicyIfNotExistWritesChildOnlyWhenChildIsMissing(): void
    {
        // Arrange
        $this->storage->getStrategy()->setChildWritePolicy(StrategyInterface::POLICY_CHILD_WRITE_IF_NOT_EXIST);

        $child = new ChildObject('Child', 10);
        $parent = new ParentObject('Parent', $child);

        // First store: both should exist afterwards
        $parentUuid = $this->storage->store($parent);
        $childUuid = $child->getUUID();

        $this->writerSpy->clearMethodCalls();

        // Act: modify child but store parent; since child already exists, it must NOT be written under IF_NOT_EXIST
        $child->value = 999;
        $this->storage->store($parent, $parentUuid);

        // Assert
        $childCalls = count($this->writerSpy->getCallsForUuid($childUuid));
        $this->assertSame(
            0,
            $childCalls,
            'Child must NOT be written when policy is IF_NOT_EXIST and child already exists'
        );
    }

    public function testPolicyAlwaysCascadesAndWritesModifiedChildWhenStoringParent(): void
    {
        // Arrange
        $this->storage->getStrategy()->setChildWritePolicy(StrategyInterface::POLICY_CHILD_WRITE_ALWAYS);

        $child = new ChildObject('Child', 10);
        $parent = new ParentObject('Parent', $child);

        $parentUuid = $this->storage->store($parent);
        $childUuid = $child->getUUID();

        $this->writerSpy->clearMethodCalls();

        // Act: modify child, then store parent; ALWAYS should cascade and persist the child change
        $child->value = 12345;
        $this->storage->store($parent, $parentUuid);

        // Assert: child must have been written due to cascading under ALWAYS
        $childCalls = count($this->writerSpy->getCallsForUuid($childUuid));
        $this->assertGreaterThan(
            0,
            $childCalls,
            'Child should be written when policy is ALWAYS and parent is stored'
        );

        // Extra safety: verify persistence
        $this->storage->clearCache();
        $reloadedChild = $this->storage->load($childUuid);
        $this->assertSame(12345, $reloadedChild->value);
    }
}