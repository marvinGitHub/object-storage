<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\File\IO\RealAdapter;
use melia\ObjectStorage\File\Files;
use melia\ObjectStorage\ObjectStorage;

/**
 * The IO adapter given to the storage must be used for reading and writing object files as well.
 */
final class ObjectStorageIOAdapterTest extends TestCase
{
    private function createRecordingAdapter(): RecordingAdapter
    {
        return new RecordingAdapter();
    }

    private function createStorageWithAdapter(RealAdapter $adapter): ObjectStorage
    {
        $dir = $this->createTemporaryDirectory();
        $this->storageDirs[] = $dir;

        return new ObjectStorage($dir, adapter: $adapter);
    }

    public function testDefaultWriterAndReaderUseTheAdapterOfTheStorage(): void
    {
        $adapter = $this->createRecordingAdapter();
        $storage = $this->createStorageWithAdapter($adapter);

        $uuid = $storage->store(new ChildObject('child', 1));
        $this->assertContains($storage->getFilePathData($uuid) . Files::SUFFIX_PENDING, $adapter->written);
        $this->assertContains($storage->getFilePathMetadata($uuid) . Files::SUFFIX_PENDING, $adapter->written);

        $storage->clearCache();
        $this->assertSame('child', $storage->load($uuid)->title);
        $this->assertContains($storage->getFilePathData($uuid), $adapter->read);
        $this->assertContains($storage->getFilePathMetadata($uuid), $adapter->read);
    }

    public function testDefaultFilesFollowAChangedAdapter(): void
    {
        $storage = $this->createStorageWithAdapter($this->createRecordingAdapter());
        $storage->getFiles();

        $adapter = $this->createRecordingAdapter();
        $storage->setIOAdapter($adapter);

        $uuid = $storage->store(new ChildObject('child', 1));
        $storage->clearCache();
        $storage->load($uuid);

        $this->assertContains($storage->getFilePathData($uuid) . Files::SUFFIX_PENDING, $adapter->written);
        $this->assertContains($storage->getFilePathData($uuid), $adapter->read);
    }

    public function testExplicitlySetWriterKeepsItsAdapter(): void
    {
        $storageAdapter = $this->createRecordingAdapter();
        $storage = $this->createStorageWithAdapter($storageAdapter);

        $writerAdapter = $this->createRecordingAdapter();
        $writer = new Files();
        $writer->setIOAdapter($writerAdapter);
        $storage->setFiles($writer);

        $storage->setIOAdapter($this->createRecordingAdapter());
        $uuid = $storage->store(new ChildObject('child', 1));

        $this->assertSame($writerAdapter, $writer->getIOAdapter());
        $this->assertContains($storage->getFilePathData($uuid) . Files::SUFFIX_PENDING, $writerAdapter->written);
        $this->assertSame([], $storageAdapter->written);
    }

    public function testADeleteIsFlushedToDiskLikeAWrite(): void
    {
        $adapter = $this->createRecordingAdapter();
        $storage = $this->createStorageWithAdapter($adapter);
        $uuid = $storage->store(new ChildObject('child', 1));
        $directory = dirname($storage->getFilePathData($uuid));

        $adapter->flushedDirectories = [];
        $storage->delete($uuid);

        $this->assertFalse($storage->exists($uuid));
        $this->assertSame([$directory], $adapter->flushedDirectories, 'the directory of the removed files, once');
    }

    public function testADeleteWithoutSyncIsNotFlushed(): void
    {
        $adapter = $this->createRecordingAdapter();
        $storage = $this->createStorageWithAdapter($adapter);
        $storage->getFiles()->setSyncEnabled(false);
        $uuid = $storage->store(new ChildObject('child', 1));

        $storage->delete($uuid);

        $this->assertFalse($storage->exists($uuid));
        $this->assertSame([], $adapter->flushedDirectories);
    }
}

/**
 * Records the files written and read through it.
 */
class RecordingAdapter extends RealAdapter
{
    public array $written = [];
    public array $read = [];
    public array $flushedDirectories = [];

    public function fsyncDirectory(string $directory): bool
    {
        $this->flushedDirectories[] = $directory;
        return parent::fsyncDirectory($directory);
    }

    public function filePutContents(string $filename, string $data, int $flags = 0, $context = null): int|bool
    {
        $this->written[] = $filename;
        return parent::filePutContents($filename, $data, $flags, $context);
    }

    public function fileGetContents(string $filename): false|string
    {
        $this->read[] = $filename;
        return parent::fileGetContents($filename);
    }
}
