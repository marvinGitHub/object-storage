<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\File\IO\RealAdapter;
use melia\ObjectStorage\File\Writer;
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
        $this->assertContains($storage->getFilePathData($uuid) . Writer::SUFFIX_PENDING, $adapter->written);
        $this->assertContains($storage->getFilePathMetadata($uuid) . Writer::SUFFIX_PENDING, $adapter->written);

        $storage->clearCache();
        $this->assertSame('child', $storage->load($uuid)->title);
        $this->assertContains($storage->getFilePathData($uuid), $adapter->read);
        $this->assertContains($storage->getFilePathMetadata($uuid), $adapter->read);
    }

    public function testDefaultWriterAndReaderFollowAChangedAdapter(): void
    {
        $storage = $this->createStorageWithAdapter($this->createRecordingAdapter());
        $storage->getWriter();
        $storage->getReader();

        $adapter = $this->createRecordingAdapter();
        $storage->setIOAdapter($adapter);

        $uuid = $storage->store(new ChildObject('child', 1));
        $storage->clearCache();
        $storage->load($uuid);

        $this->assertContains($storage->getFilePathData($uuid) . Writer::SUFFIX_PENDING, $adapter->written);
        $this->assertContains($storage->getFilePathData($uuid), $adapter->read);
    }

    public function testExplicitlySetWriterKeepsItsAdapter(): void
    {
        $storageAdapter = $this->createRecordingAdapter();
        $storage = $this->createStorageWithAdapter($storageAdapter);

        $writerAdapter = $this->createRecordingAdapter();
        $writer = new Writer();
        $writer->setIOAdapter($writerAdapter);
        $storage->setWriter($writer);

        $storage->setIOAdapter($this->createRecordingAdapter());
        $uuid = $storage->store(new ChildObject('child', 1));

        $this->assertSame($writerAdapter, $writer->getIOAdapter());
        $this->assertContains($storage->getFilePathData($uuid) . Writer::SUFFIX_PENDING, $writerAdapter->written);
        $this->assertSame([], $storageAdapter->written);
    }
}

/**
 * Records the files written and read through it.
 */
class RecordingAdapter extends RealAdapter
{
    public array $written = [];
    public array $read = [];

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
