<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\LazyLoadReference;
use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\AwareTrait;


class TestObjectWithMultipleReferences implements AwareInterface
{
    use AwareTrait;

    public ?object $loadedChild = null;
    public ?object $nonLoadedChild = null;
    public ?object $modifiedChild = null;
}

class TestObjectWithArray implements AwareInterface
{
    use AwareTrait;

    public array $children = [];
}


class ObjectStorageChildUpdateTest extends TestCase
{
    public function testGetCallsForUuidReturnsZeroWhenObjectStoredWithoutChanges(): void
    {
        // Arrange
        $child = new ChildObject('Unchanged Child', 100);
        $parent = new ParentObject('Unchanged Parent', $child);

        // Store initial objects
        $parentUuid = $this->storage->store($parent);
        $childUuid = $child->getUUID();

        // Clear spy to track only subsequent operations
        $this->writerSpy->clearMethodCalls();

        // Act - Store the same objects again without any modifications
        $this->storage->store($parent, $parentUuid);
        $this->storage->store($child, $childUuid);

        // Assert
        $parentCalls = $this->writerSpy->getCallsForUuid($parentUuid);
        $childCalls = $this->writerSpy->getCallsForUuid($childUuid);

        $this->assertCount(0, $parentCalls, 'getCallsForUuid should return empty array (zero calls) for unchanged parent');
        $this->assertCount(0, $childCalls, 'getCallsForUuid should return empty array (zero calls) for unchanged child');

        // Additional verification
        $this->assertEquals(0, count($parentCalls), 'Parent should have zero storage calls when unchanged');
        $this->assertEquals(0, count($childCalls), 'Child should have zero storage calls when unchanged');
    }


    public function testGetCallsForUuidReturnsZeroForLazyLoadedUnchangedObject(): void
    {
        // Arrange
        $child = new ChildObject('Lazy Unchanged', 200);
        $parent = new ParentObject('Parent', $child);

        $parentUuid = $this->storage->store($parent);
        $childUuid = $child->getUUID();

        // Clear cache and spy
        $this->storage->clearCache();
        $this->writerSpy->clearMethodCalls();

        // Act - Load parent, access child but don't modify anything
        $loadedParent = $this->storage->load($parentUuid);
        $lazyChild = $loadedParent->child;

        // Access child properties to trigger loading but don't modify
        $childTitle = $lazyChild->title;
        $childValue = $lazyChild->value;

        $this->assertTrue($lazyChild->isLoaded(), 'Child should be loaded after access');

        // Store again without modifications
        $this->storage->store($loadedParent, $parentUuid);

        // Assert
        $parentCalls = $this->writerSpy->getCallsForUuid($parentUuid);
        $childCalls = $this->writerSpy->getCallsForUuid($childUuid);

        $this->assertEquals(0, count($childCalls), 'getCallsForUuid should return zero for unchanged lazy-loaded child');
        $this->assertEquals(0, count($parentCalls), 'getCallsForUuid should return zero for unchanged parent');
    }

    public function testGetCallsForUuidReturnsZeroForComplexUnchangedStructure(): void
    {
        // Arrange - Create complex nested structure
        $grandChild = new ChildObject('GrandChild', 10);
        $child = new ParentObject('Child', $grandChild);
        $parent = new ParentObject('Parent', $child);

        $parentUuid = $this->storage->store($parent);
        $childUuid = $child->getUUID();
        $grandChildUuid = $grandChild->getUUID();

        $this->writerSpy->clearMethodCalls();

        // Act - Store the entire structure again without any changes
        $this->storage->store($parent, $parentUuid);

        // Assert
        $parentCalls = count($this->writerSpy->getCallsForUuid($parentUuid));
        $childCalls = count($this->writerSpy->getCallsForUuid($childUuid));
        $grandChildCalls = count($this->writerSpy->getCallsForUuid($grandChildUuid));

        $this->assertEquals(0, $parentCalls, 'Parent getCallsForUuid should be zero when unchanged');
        $this->assertEquals(0, $childCalls, 'Child getCallsForUuid should be zero when unchanged');
        $this->assertEquals(0, $grandChildCalls, 'GrandChild getCallsForUuid should be zero when unchanged');
    }


    public function testGetCallsForUuidDistinguishesBetweenChangedAndUnchangedObjects(): void
    {
        // Arrange
        $child1 = new ChildObject('Child1', 100);
        $child2 = new ChildObject('Child2', 200);

        $parent = new TestObjectWithMultipleReferences();
        $parent->loadedChild = $child1;
        $parent->nonLoadedChild = $child2;

        $parentUuid = $this->storage->store($parent);
        $child1Uuid = $child1->getUUID();
        $child2Uuid = $child2->getUUID();

        $this->writerSpy->clearMethodCalls();

        // Act - Modify only child1, leave child2 unchanged
        $child1->value = 999;
        $this->storage->store($child1, $child1Uuid);
        $this->storage->store($child2, $child2Uuid); // Store unchanged child2

        // Assert
        $child1Calls = count($this->writerSpy->getCallsForUuid($child1Uuid));
        $child2Calls = count($this->writerSpy->getCallsForUuid($child2Uuid));

        $this->assertGreaterThan(0, $child1Calls, 'Modified child1 should have calls > 0');
        $this->assertEquals(0, $child2Calls, 'Unchanged child2 getCallsForUuid should be zero');
    }


    public function testGetCallsForUuidReturnsZeroAfterMultipleUnchangedStorageAttempts(): void
    {
        // Arrange
        $child = new ChildObject('Multiple Storage Test', 42);
        $parentUuid = $this->storage->store($child);

        $this->writerSpy->clearMethodCalls();

        // Act - Store the same object multiple times without changes
        $this->storage->store($child, $parentUuid);
        $this->storage->store($child, $parentUuid);
        $this->storage->store($child, $parentUuid);

        // Assert
        $calls = $this->writerSpy->getCallsForUuid($parentUuid);
        $this->assertEquals(0, count($calls), 'getCallsForUuid should return zero even after multiple unchanged storage attempts');
    }


    public function testGetCallsForUuidReturnsZeroForArrayWithUnchangedElements(): void
    {
        // Arrange
        $child1 = new ChildObject('Array Element 1', 1);
        $child2 = new ChildObject('Array Element 2', 2);

        $parent = new TestObjectWithArray();
        $parent->children = [$child1, $child2];

        $parentUuid = $this->storage->store($parent);
        $child1Uuid = $child1->getUUID();
        $child2Uuid = $child2->getUUID();

        $this->writerSpy->clearMethodCalls();

        // Act - Store parent with unchanged array elements
        $this->storage->store($parent, $parentUuid);

        // Assert
        $parentCalls = count($this->writerSpy->getCallsForUuid($parentUuid));
        $child1Calls = count($this->writerSpy->getCallsForUuid($child1Uuid));
        $child2Calls = count($this->writerSpy->getCallsForUuid($child2Uuid));

        $this->assertEquals(0, $parentCalls, 'Parent with unchanged array should have zero calls');
        $this->assertEquals(0, $child1Calls, 'Unchanged array element 1 should have zero calls');
        $this->assertEquals(0, $child2Calls, 'Unchanged array element 2 should have zero calls');
    }

    public function testChildObjectChangeDoesNotUpdateParent(): void
    {
        // Arrange
        $child = new ChildObject('Child Title', 100);
        $parent = new ParentObject('Parent Name', $child);

        // Act - Store parent (which also stores child)
        $parentUuid = $this->storage->store($parent);
        $childUuid = $child->getUUID();

        // Debugging: Prüfe ob UUIDs korrekt gesetzt sind
        $this->assertNotNull($parentUuid, 'Parent UUID should not be null');
        $this->assertNotNull($childUuid, 'Child UUID should not be null');

        $initialCalls = $this->writerSpy->getMethodCalls();
        $initialParentCalls = count($this->writerSpy->getCallsForUuid($parentUuid));
        $initialChildCalls = count($this->writerSpy->getCallsForUuid($childUuid));

        // Clear call history
        $this->writerSpy->clearMethodCalls();

        // Act - Modify only the child object
        $child->value = 200;
        $child->title = 'Updated Child Title';

        // Store only the child (parent should not be affected)
        $this->storage->store($child, $childUuid);

        $afterChildUpdateCalls = $this->writerSpy->getMethodCalls();
        $parentCallsAfterChildUpdate = count($this->writerSpy->getCallsForUuid($parentUuid));
        $childCallsAfterChildUpdate = count($this->writerSpy->getCallsForUuid($childUuid));

        // Debug-Ausgaben hinzufügen
        $this->assertIsArray($this->writerSpy->getCallsForUuid($parentUuid), 'Parent calls should be array');
        $this->assertIsArray($this->writerSpy->getCallsForUuid($childUuid), 'Child calls should be array');

        // Assert
        $this->assertGreaterThan(0, $initialParentCalls, 'Parent should be written initially');
        $this->assertGreaterThan(0, $initialChildCalls, 'Child should be written initially');

        $this->assertEquals(0, $parentCallsAfterChildUpdate, 'Parent should NOT be written when child is updated');
        $this->assertGreaterThan(0, $childCallsAfterChildUpdate, 'Child should be written when updated');

        // Verify parent object unchanged by loading and checking
        $this->storage->clearCache();
        $loadedParent = $this->storage->load($parentUuid);

        $this->assertEquals('Parent Name', $loadedParent->name, 'Parent name should remain unchanged');
        $this->assertEquals('Updated Child Title', $loadedParent->child->title, 'Child title should be updated');
        $this->assertEquals(200, $loadedParent->child->value, 'Child value should be updated');
    }

    public function testParentObjectChangeDoesNotUpdateChild(): void
    {
        // Arrange
        $child = new ChildObject('Original Child', 50);
        $parent = new ParentObject('Original Parent', $child);

        // Store both objects
        $parentUuid = $this->storage->store($parent);
        $childUuid = $child->getUUID();

        $this->writerSpy->clearMethodCalls();

        // Act - Modify only parent object
        $parent->name = 'Updated Parent Name';
        $this->storage->store($parent, $parentUuid);

        $parentCalls = count($this->writerSpy->getCallsForUuid($parentUuid));
        $childCalls = count($this->writerSpy->getCallsForUuid($childUuid));

        // Assert
        $this->assertGreaterThan(0, $parentCalls, 'Parent should be written when updated');
        $this->assertEquals(0, $childCalls, 'Child should NOT be written when only parent is updated');

        // Verify child unchanged
        $this->storage->clearCache();
        $loadedChild = $this->storage->load($childUuid);

        $this->assertEquals('Original Child', $loadedChild->title, 'Child should remain unchanged');
        $this->assertEquals(50, $loadedChild->value, 'Child value should remain unchanged');
    }

    public function testComplexNestedObjectsWithMixedUpdates(): void
    {
        // Arrange - Create nested structure
        $grandChild = new ChildObject('GrandChild', 10);
        $child = new ChildObject('Child', 20);
        $parent = new ParentObject('Parent', $child);

        // Create a complex nested structure by adding grandChild to child
        $child->title = 'Child with nested object';

        // Store the structure
        $parentUuid = $this->storage->store($parent);
        $childUuid = $child->getUUID();
        $grandChildUuid = $this->storage->store($grandChild);

        $this->writerSpy->clearMethodCalls();

        // Act - Update only the grandchild
        $grandChild->value = 999;
        $this->storage->store($grandChild, $grandChildUuid);

        $parentCalls = count($this->writerSpy->getCallsForUuid($parentUuid));
        $childCalls = count($this->writerSpy->getCallsForUuid($childUuid));
        $grandChildCalls = count($this->writerSpy->getCallsForUuid($grandChildUuid));

        // Assert
        $this->assertEquals(0, $parentCalls, 'Parent should not be updated');
        $this->assertEquals(0, $childCalls, 'Child should not be updated');
        $this->assertGreaterThan(0, $grandChildCalls, 'GrandChild should be updated');

        // Verify isolation
        $this->storage->clearCache();
        $loadedParent = $this->storage->load($parentUuid);
        $loadedGrandChild = $this->storage->load($grandChildUuid);

        $this->assertEquals('Parent', $loadedParent->name, 'Parent unchanged');
        $this->assertEquals('Child with nested object', $loadedParent->child->title, 'Child unchanged');
        $this->assertEquals(999, $loadedGrandChild->value, 'GrandChild updated');
    }

    public function testFileTimestampsShowNoParentUpdate(): void
    {
        // Arrange
        $child = new ChildObject('Timestamp Test Child', 42);
        $parent = new ParentObject('Timestamp Test Parent', $child);

        $parentUuid = $this->storage->store($parent);
        $childUuid = $child->getUUID();

        $parentDataFile = $this->storageDir . DIRECTORY_SEPARATOR . $parentUuid . '.obj';
        $parentMetadataFile = $this->storageDir . DIRECTORY_SEPARATOR . $parentUuid . '.metadata';
        $childDataFile = $this->storageDir . DIRECTORY_SEPARATOR . $childUuid . '.obj';

        // Get initial timestamps
        clearstatcache();
        $initialParentDataTime = filemtime($parentDataFile);
        $initialParentMetadataTime = filemtime($parentMetadataFile);
        $initialChildDataTime = filemtime($childDataFile);

        sleep(1); // Ensure timestamp difference would be detectable

        // Act - Update child only
        $child->value = 84;
        $this->storage->store($child, $childUuid);

        // Get final timestamps
        clearstatcache();
        $finalParentDataTime = filemtime($parentDataFile);
        $finalParentMetadataTime = filemtime($parentMetadataFile);
        $finalChildDataTime = filemtime($childDataFile);

        // Assert
        $this->assertEquals($initialParentDataTime, $finalParentDataTime,
            'Parent data file should not be modified');
        $this->assertEquals($initialParentMetadataTime, $finalParentMetadataTime,
            'Parent metadata file should not be modified');
        $this->assertGreaterThan($initialChildDataTime, $finalChildDataTime,
            'Child data file should be modified');
    }

    public function testLazyLoadedChildUpdateDoesNotAffectParent(): void
    {
        // Arrange - Create objects and store them
        $child = new ChildObject('Lazy Child', 300);
        $parent = new ParentObject('Lazy Parent', $child);

        $parentUuid = $this->storage->store($parent);
        $childUuid = $child->getUUID();

        // Clear cache to force lazy loading
        $this->storage->clearCache();
        $this->writerSpy->clearMethodCalls();

        // Act - Load parent (child should be lazy-loaded)
        $loadedParent = $this->storage->load($parentUuid);

        // Access child to trigger lazy loading
        $lazyChild = $loadedParent->child;
        $originalValue = $lazyChild->value; // This should trigger loading

        $this->writerSpy->clearMethodCalls();

        $this->assertCount(0, $this->storage->getLockAdapter()->getActiveLocks());

        // Modify the lazy-loaded child
        $lazyChild->value = 999;
        $this->storage->store($lazyChild, $childUuid);

        $parentCallsAfterChildUpdate = count($this->writerSpy->getCallsForUuid($parentUuid));
        $childCallsAfterChildUpdate = count($this->writerSpy->getCallsForUuid($childUuid));

        // Assert
        $this->assertEquals(300, $originalValue, 'Original value should be loaded correctly');
        $this->assertEquals(0, $parentCallsAfterChildUpdate, 'Parent should not be updated');
        $this->assertGreaterThan(0, $childCallsAfterChildUpdate, 'Child should be updated');

        // Verify change persisted but parent unchanged
        $this->storage->clearCache();
        $reloadedParent = $this->storage->load($parentUuid);
        $reloadedChild = $this->storage->load($childUuid);

        $this->assertEquals('Lazy Parent', $reloadedParent->name, 'Parent name unchanged');
        $this->assertEquals(999, $reloadedChild->value, 'Child value updated');
        $this->assertEquals(999, $reloadedParent->child->value, 'Child accessible through parent updated');
    }

    public function testNonLoadedLazyLoadReferenceIsNotStored(): void
    {
        // Arrange - Create objects with circular reference
        $child = new ChildObject('Lazy Child', 100);
        $parent = new ParentObject('Parent Name', $child);

        // Store parent (which also stores child)
        $parentUuid = $this->storage->store($parent);
        $childUuid = $child->getUUID();

        // Clear cache to force lazy loading
        $this->storage->clearCache();
        $this->writerSpy->clearMethodCalls();

        // Act - Load parent (child becomes LazyLoadReference but is NOT accessed)
        $loadedParent = $this->storage->load($parentUuid);

        // Verify child is lazy loaded but not accessed
        $this->assertInstanceOf(LazyLoadReference::class, $loadedParent->child);
        $this->assertFalse($loadedParent->child->isLoaded(), 'LazyLoadReference should not be loaded initially');

        $this->writerSpy->clearMethodCalls();

        // Store parent again - non-loaded lazy references should NOT be stored
        $this->storage->store($loadedParent, $parentUuid);

        $parentCalls = count($this->writerSpy->getCallsForUuid($parentUuid));
        $childCalls = count($this->writerSpy->getCallsForUuid($childUuid));

        // Assert
        $this->assertEquals(0, $parentCalls, 'Parent should not be written');
        $this->assertEquals(0, $childCalls, 'Non-loaded LazyLoadReference should NOT trigger child storage');

        // Verify the lazy reference is still not loaded after storage
        $this->assertFalse($loadedParent->child->isLoaded(), 'LazyLoadReference should remain unloaded after storage');
    }


    public function testLoadedLazyLoadReferenceIsStored(): void
    {
        // Arrange - Create objects with circular reference
        $child = new ChildObject('Loaded Child', 200);
        $parent = new ParentObject('Parent Name', $child);

        // Store parent (which also stores child)
        $parentUuid = $this->storage->store($parent);
        $childUuid = $child->getUUID();

        // Clear cache to force lazy loading
        $this->storage->clearCache();
        $this->writerSpy->clearMethodCalls();

        // Act - Load parent and access child to trigger loading
        $loadedParent = $this->storage->load($parentUuid);

        // Access child property to trigger lazy loading
        $loadedChild = $loadedParent->child;
        $childValue = $loadedParent->child->value;

        $this->assertInstanceOf(LazyLoadReference::class, $loadedChild);
        $this->assertTrue($loadedChild->isLoaded(), 'LazyLoadReference should be loaded after access');
        $this->assertEquals(200, $childValue, 'Child value should be accessible');

        // Modify the loaded child
        $loadedParent->child->value = 999;

        $this->writerSpy->clearMethodCalls();

        // Store parent - loaded lazy references should be processed
        $this->storage->store($loadedParent, $parentUuid);

        $parentCalls = count($this->writerSpy->getCallsForUuid($parentUuid));
        $childCalls = count($this->writerSpy->getCallsForUuid($childUuid));

        // Assert
        $this->assertEquals(0, $parentCalls, 'Parent should not be written since only child is modified');
        $this->assertGreaterThan(0, $childCalls, 'Loaded LazyLoadReference should trigger child storage');

        // Verify changes persisted
        $this->storage->clearCache();
        $reloadedChild = $this->storage->load($childUuid);
        $this->assertEquals(999, $reloadedChild->value, 'Modified child value should persist');
    }

    public function testMixedLoadedAndNonLoadedLazyReferences(): void
    {
        // Arrange - Create complex structure with multiple references
        $child1 = new ChildObject('Child1', 100);
        $child2 = new ChildObject('Child2', 200);
        $child3 = new ChildObject('Child3', 300);

        $parent = new TestObjectWithMultipleReferences();
        $parent->loadedChild = $child1;
        $parent->nonLoadedChild = $child2;
        $parent->modifiedChild = $child3;

        // Store all objects
        $parentUuid = $this->storage->store($parent);
        $child1Uuid = $child1->getUUID();
        $child2Uuid = $child2->getUUID();
        $child3Uuid = $child3->getUUID();

        // Clear cache and spy
        $this->storage->clearCache();
        $this->writerSpy->clearMethodCalls();

        // Act - Load parent and selectively access children
        $loadedParent = $this->storage->load($parentUuid);

        // Access only child1 and child3, leaving child2 unloaded
        $loadedChild = $loadedParent->loadedChild;
        $modifiedChild = $loadedParent->modifiedChild;
        $nonLoadedChild = $loadedParent->nonLoadedChild;
        $child1Value = $loadedChild->value; // This loads child1
        $loadedParent->modifiedChild->value = 999; // This loads and modifies child3
        // child2 remains unloaded

        $this->assertTrue($loadedChild->isLoaded(), 'Child1 should be loaded');
        $this->assertTrue($modifiedChild->isLoaded(), 'Child3 should be loaded');
        $this->assertFalse($nonLoadedChild->isLoaded(), 'Child2 should remain unloaded');

        $this->writerSpy->clearMethodCalls();

        // Store parent
        $this->storage->store($loadedParent, $parentUuid);

        $parentCalls = count($this->writerSpy->getCallsForUuid($parentUuid));
        $child1Calls = count($this->writerSpy->getCallsForUuid($child1Uuid));
        $child2Calls = count($this->writerSpy->getCallsForUuid($child2Uuid));
        $child3Calls = count($this->writerSpy->getCallsForUuid($child3Uuid));

        // Assert
        //$this->assertEquals(0, $parentCalls, 'Parent should not be written since only childs are modified');
        $this->assertEquals(0, $child1Calls, 'Loaded but unmodified child1 should not be stored');
        $this->assertEquals(0, $child2Calls, 'Non-loaded child2 should not be stored');
        $this->assertGreaterThan(0, $child3Calls, 'Modified child3 should be stored');
    }

    public function testLazyReferenceSkippedDuringProcessing(): void
    {
        // Arrange
        $child = new ChildObject('Skip Test', 42);
        $parent = new ParentObject('Parent', $child);

        $parentUuid = $this->storage->store($parent);
        $childUuid = $child->getUUID();

        // Clear cache
        $this->storage->clearCache();

        // Load parent without accessing child
        $loadedParent = $this->storage->load($parentUuid);
        $loadedParent->someRandomValue = 100;
        $lazyChild = $loadedParent->child;

        $this->assertInstanceOf(LazyLoadReference::class, $lazyChild);
        $this->assertFalse($lazyChild->isLoaded(), 'Child should not be loaded initially');

        // Clear writer spy to track only the next storage operation
        $this->writerSpy->clearMethodCalls();

        // Store the parent with the unloaded lazy reference
        $this->storage->store($loadedParent, $parentUuid);

        // Get storage data to verify lazy reference was skipped
        $allCalls = $this->writerSpy->getMethodCalls();
        $childCalls = $this->writerSpy->getCallsForUuid($childUuid);

        // Assert that child was not processed during storage
        $this->assertCount(0, $childCalls, 'Unloaded lazy reference should be skipped during storage');
        $this->assertGreaterThan(0, count($allCalls), 'Parent should still be stored');

        // Verify the lazy reference can still be loaded later
        $this->storage->clearCache();
        $reloadedParent = $this->storage->load($parentUuid);
        $this->assertEquals('Skip Test', $reloadedParent->child->title, 'Lazy reference should still be accessible');
        $this->assertEquals(100, $reloadedParent->someRandomValue, 'Some random value should still be accessible');
    }

    public function testNestedLazyReferencesOnlyLoadedOnesStored(): void
    {
        // Arrange - Create nested structure
        $grandChild = new ChildObject('GrandChild', 1);
        $child = new ParentObject('Child', $grandChild);
        $parent = new ParentObject('Parent', $child);

        $parentUuid = $this->storage->store($parent);
        $childUuid = $child->getUUID();
        $grandChildUuid = $grandChild->getUUID();

        // Clear cache
        $this->storage->clearCache();
        $this->writerSpy->clearMethodCalls();

        // Load parent and access only the first level child
        $loadedParent = $this->storage->load($parentUuid);
        $firstLevelChild = $loadedParent->child; // This loads child but grandChild remains lazy

        // Access child name to ensure it's loaded
        $childName = $firstLevelChild->name;

        $this->assertTrue($firstLevelChild->isLoaded(), 'First level child should be loaded');
        $this->assertInstanceOf(LazyLoadReference::class, $firstLevelChild->child);
        $this->assertFalse($firstLevelChild->child->isLoaded(), 'Grandchild should remain unloaded');

        $this->writerSpy->clearMethodCalls();

        // Store parent
        $this->storage->store($loadedParent, $parentUuid);

        $parentCalls = count($this->writerSpy->getCallsForUuid($parentUuid));
        $childCalls = count($this->writerSpy->getCallsForUuid($childUuid));
        $grandChildCalls = count($this->writerSpy->getCallsForUuid($grandChildUuid));

        // Assert 1 call means only metadata has been written
        $this->assertEquals(0, $parentCalls, 'Parent should not be stored (unmodified)');
        $this->assertEquals(0, $childCalls, 'Child should not be stored (unmodified)');
        $this->assertEquals(0, $grandChildCalls, 'Grandchild should not be stored (unloaded lazy reference)');
    }

    public function testArrayWithLazyReferencesProcessedCorrectly(): void
    {
        // Arrange - Create object with array containing lazy references
        $child1 = new ChildObject('Array Child 1', 10);
        $child2 = new ChildObject('Array Child 2', 20);
        $child3 = new ChildObject('Array Child 3', 30);

        $parent = new TestObjectWithArray();
        $parent->children = [$child1, $child2, $child3];

        $parentUuid = $this->storage->store($parent);
        $child1Uuid = $child1->getUUID();
        $child2Uuid = $child2->getUUID();
        $child3Uuid = $child3->getUUID();

        // Clear cache
        $this->storage->clearCache();

        // Load parent - children become lazy references
        $loadedParent = $this->storage->load($parentUuid);

        // Access only the second child
        $firstChild = $loadedParent->children[0];
        $this->assertFalse($firstChild->isLoaded(), 'First child should remain unloaded');

        $secondChild = $loadedParent->children[1];
        $secondChildValue = $secondChild->value;
        $this->assertEquals('Array Child 2', $secondChild->title, 'Second child should be loaded');
        $this->assertEquals(20, $secondChildValue, 'Second child should be loaded');
        $this->assertFalse($firstChild->isLoaded(), 'First child should remain unloaded');
        $this->assertTrue($secondChild->isLoaded(), 'Second child should be loaded');

        $thirdChild = $loadedParent->children[2];
        $this->assertFalse($thirdChild->isLoaded(), 'Third child should remain unloaded');

        $this->writerSpy->clearMethodCalls();

        // Store parent
        $this->storage->store($loadedParent, $parentUuid);

        $child1Calls = count($this->writerSpy->getCallsForUuid($child1Uuid));
        $child2Calls = count($this->writerSpy->getCallsForUuid($child2Uuid));
        $child3Calls = count($this->writerSpy->getCallsForUuid($child3Uuid));

        // Assert - only loaded references should be processed
        $this->assertEquals(0, $child1Calls, 'Unloaded child1 should not be stored');
        $this->assertEquals(0, $child2Calls, 'Loaded but unmodified child2 should not be stored');
        $this->assertEquals(0, $child3Calls, 'Unloaded child3 should not be stored');
    }


}