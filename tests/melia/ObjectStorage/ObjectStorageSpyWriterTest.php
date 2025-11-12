<?php

namespace Tests\melia\ObjectStorage;

class ObjectStorageSpyWriterTest extends TestCase
{
    public function testStorageOfUnchangedObjectDoesNotCallAtomicWrite(): void
    {
        // Arrange
        $user = new TestUser('John Doe', 30);

        // Act - First store should trigger atomic writes
        $uuid = $this->storage->store($user);
        $callsAfterFirstStore = $this->writerSpy->getAtomicWriteCallCount();

        // Clear call history to focus on second store
        $this->writerSpy->clearMethodCalls();

        // Act - Store the same unchanged object again
        $this->storage->store($user, $uuid);
        $callsAfterSecondStore = $this->writerSpy->getAtomicWriteCallCount();

        // 3 -> data, metadata and stub, classname
        $this->assertEquals(4, $callsAfterFirstStore, 'First store should write data and metadata files, stub and classname');
        $this->assertEquals(0, $callsAfterSecondStore, 'Second store should not write any files (object unchanged)');

        // Verify files actually exist (proving real writer was called)
        $this->assertTrue(file_exists($this->storage->getStorageDir() . DIRECTORY_SEPARATOR . $uuid . '.obj'));
        $this->assertTrue(file_exists($this->storage->getStorageDir() . DIRECTORY_SEPARATOR . $uuid . '.metadata'));
    }

    public function testStorageOfChangedObjectDoesCallAtomicWrite(): void
    {
        // Arrange
        $user = new TestUser('John Doe', 30);

        // Act - Initial store
        $uuid = $this->storage->store($user);
        $this->writerSpy->clearMethodCalls();

        // Modify the object
        $user->age = 31;

        // Act - Store the modified object
        $this->storage->store($user, $uuid);
        $callsAfterModification = $this->writerSpy->getAtomicWriteCallCount();

        // 3 -> data, metadata (stub won't be regenerated since it exists already)
        $this->assertEquals(2, $callsAfterModification, 'Modified object should trigger writes');

        // Verify the content actually changed by loading and checking
        $this->storage->clearCache();
        $loadedUser = $this->storage->load($uuid);
        $this->assertEquals(31, $loadedUser->age, 'Loaded user should have updated age');
    }

    public function testDetailedCallTracking(): void
    {
        // Arrange
        $user = new TestUser('Jane Smith', 25);

        // Act - Store object
        $uuid = $this->storage->store($user);
        $calls = $this->writerSpy->getMethodCalls();

        // Assert - Verify call details
        $this->assertCount(4, $calls, 'Should have 2 calls (data + metadata + stub + classname)');

        // Check first call (data file)
        $dataCall = $calls[0];
        $this->assertEquals('atomicWrite', $dataCall['method']);
        $this->assertStringEndsWith('.obj', $dataCall['filename']);
        $this->assertGreaterThan(0, $dataCall['data_length']);
        $this->assertIsFloat($dataCall['timestamp']);

        // Check second call (metadata file)
        $metadataCall = $calls[1];
        $this->assertEquals('atomicWrite', $metadataCall['method']);
        $this->assertStringEndsWith('.metadata', $metadataCall['filename']);
        $this->assertGreaterThan(0, $metadataCall['data_length']);

        // Verify files contain expected content
        $dataContent = file_get_contents($dataCall['filename']);
        $this->assertNotEmpty($dataContent);

        $dataArray = json_decode($dataContent, true);

        $metadataContent = file_get_contents($metadataCall['filename']);
        $metadataArray = json_decode($metadataContent, true);
        $this->assertArrayHasKey('className', $metadataArray);
        $this->assertArrayHasKey('checksum', $metadataArray);
        $this->assertArrayHasKey('timestampCreation', $metadataArray);
        $this->assertArrayHasKey('uuid', $metadataArray);
        $this->assertArrayHasKey('timestampExpiresAt', $metadataArray);
        $this->assertArrayHasKey('reservedReferenceName', $metadataArray);
        $this->assertEquals(TestUser::class, $metadataArray['className']);

    }

    public function testMultipleUnchangedStoresWithRealFiles(): void
    {
        // Arrange
        $user = new TestUser('Multi Test', 40);

        // Act - First store
        $uuid = $this->storage->store($user);
        $dataFile = $this->storage->getStorageDir() . DIRECTORY_SEPARATOR . $uuid . '.obj';
        $metadataFile = $this->storage->getStorageDir() . DIRECTORY_SEPARATOR . $uuid . '.metadata';

        // Get initial file timestamps
        clearstatcache();
        $initialDataTime = filemtime($dataFile);
        $initialMetadataTime = filemtime($metadataFile);
        $initialCallCount = $this->writerSpy->getAtomicWriteCallCount();

        // Wait to ensure timestamp difference would be detectable
        sleep(1);
        $this->writerSpy->clearMethodCalls();

        // Act - Store unchanged object multiple times
        $this->storage->store($user, $uuid);
        $this->storage->store($user, $uuid);
        $this->storage->store($user, $uuid);

        $subsequentCallCount = $this->writerSpy->getAtomicWriteCallCount();

        // Get final file timestamps
        clearstatcache();
        $finalDataTime = filemtime($dataFile);
        $finalMetadataTime = filemtime($metadataFile);

        // Assert
        $this->assertEquals(4, $initialCallCount, 'Initial store should write 2 files (data, metadata and stub, classname)');
        $this->assertEquals(0, $subsequentCallCount, 'Subsequent stores should not write any files');
        $this->assertEquals($initialDataTime, $finalDataTime, 'Data file timestamp should not change');
        $this->assertEquals($initialMetadataTime, $finalMetadataTime, 'Metadata file timestamp should not change');

        // Verify object can still be loaded correctly
        $this->storage->clearCache();
        $loadedUser = $this->storage->load($uuid);
        $this->assertEquals('Multi Test', $loadedUser->name);
        $this->assertEquals(40, $loadedUser->age);
    }

    public function testMixedScenarioWithChangeDetection(): void
    {
        // Arrange
        $user = new TestUser('Change Detection', 50);

        // Initial store
        $uuid = $this->storage->store($user);
        $this->writerSpy->clearMethodCalls();

        // Store unchanged - should not write
        $this->storage->store($user, $uuid);
        $unchangedCalls = $this->writerSpy->getAtomicWriteCallCount();

        // Make a change and store - should write
        $user->name = 'Changed Name';
        $this->storage->store($user, $uuid);
        $changedCalls = $this->writerSpy->getAtomicWriteCallCount();

        // Store unchanged again - should not write
        $this->writerSpy->clearMethodCalls();
        $this->storage->store($user, $uuid);
        $unchangedAgainCalls = $this->writerSpy->getAtomicWriteCallCount();

        // Assert
        $this->assertEquals(0, $unchangedCalls, 'Unchanged store should not write');

        // 2 -> data, metadata (stub won't be regenerated since it exists already)
        $this->assertEquals(2, $changedCalls, 'Changed store should write data + metadata + stub');
        $this->assertEquals(0, $unchangedAgainCalls, 'Unchanged store after change should not write');

        // Verify the change persisted
        $this->storage->clearCache();
        $loadedUser = $this->storage->load($uuid);
        $this->assertEquals('Changed Name', $loadedUser->name);
    }
}