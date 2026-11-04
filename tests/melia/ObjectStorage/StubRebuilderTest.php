<?php

namespace Tests\melia\ObjectStorage;

use ArrayObject;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Util\Maintenance\StubRebuilder;
use RuntimeException;
use SplStack;

class StubRebuilderTest extends TestCase
{
    private function createRebuilder(ObjectStorage $storage): StubRebuilder
    {
        $rebuilder = new StubRebuilder();
        $rebuilder->setStorage($storage);
        return $rebuilder;
    }

    /**
     * @return string[]
     */
    private function stubFiles(ObjectStorage $storage): array
    {
        $files = glob($storage->getStubDirectory() . '/*/*.stub') ?: [];
        sort($files);
        return $files;
    }

    public function testMissingStubsAreCreatedAndTheRegistryKeptForOtherProcesses(): void
    {
        $a = $this->storage->store(new ArrayObject([1]));
        $b = $this->storage->store(new SplStack());

        // the stubs and the registry are lost
        foreach ($this->stubFiles($this->storage) as $file) {
            unlink($file);
        }
        unlink($this->storage->getStubDirectory() . '/classnames.json');

        $progress = $this->storage->rebuildStubs();

        $this->assertTrue($progress['done']);
        $this->assertSame(2, $progress['created']);
        $this->assertSame([$a], iterator_to_array($this->storage->list(ArrayObject::class), false));
        $this->assertSame([$b], iterator_to_array($this->storage->list(SplStack::class), false));

        // another process sees the classes: the registry was written again
        $other = new ObjectStorage($this->storage->getStorageDir());
        $classNames = array_values($other->getClassNames());
        sort($classNames);
        $this->assertSame([ArrayObject::class, SplStack::class], $classNames);
    }

    public function testRebuildInTheSameProcessKeepsTheStubs(): void
    {
        // previously, rebuilding removed the stub directory, and the stubs couldn't be created again in the same
        // process, as the removed directories still counted as existing
        $this->storage->store(new ArrayObject([1]));
        $this->storage->store(new SplStack());

        $this->storage->rebuildStubs();

        $this->assertCount(2, $this->stubFiles($this->storage));
        $this->assertCount(2, (new ObjectStorage($this->storage->getStorageDir()))->getClassNames());
    }

    public function testWrongStubsAreRemovedAndUnusedClassesUnregistered(): void
    {
        $kept = $this->storage->store(new ArrayObject([1]));
        $deleted = $this->storage->store(new SplStack());

        // the object is deleted without its stub (e.g. its files removed by hand)
        unlink($this->storage->getFilePathData($deleted));
        unlink($this->storage->getFilePathMetadata($deleted));

        // a stub in the directory of another class
        $wrong = $this->storage->getFilePathStub(SplStack::class, $kept);
        touch($wrong);

        $progress = $this->createRebuilder(new ObjectStorage($this->storage->getStorageDir()))->rebuildStubs();

        $this->assertSame(0, $progress['created']);
        $this->assertSame(2, $progress['removed']);
        $this->assertSame([$this->storage->getFilePathStub(ArrayObject::class, $kept)], $this->stubFiles($this->storage));
        $this->assertDirectoryDoesNotExist($this->storage->getClassStubDirectory(SplStack::class), 'empty stub directories are removed');

        $other = new ObjectStorage($this->storage->getStorageDir());
        $this->assertSame([ArrayObject::class], array_values($other->getClassNames()), 'classes without objects are unregistered');
    }

    public function testRebuildInStepsReportsTheProgress(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->storage->store(new ArrayObject([$i]));
        }

        $rebuilder = $this->createRebuilder($this->storage);
        $progress = $rebuilder->start();
        $this->assertSame(['phase' => 'objects', 'total' => 12, 'processed' => 0, 'percent' => 0, 'done' => false], array_intersect_key($progress, array_flip(['phase', 'total', 'processed', 'percent', 'done'])));

        $reports = [];
        while (!$progress['done']) {
            $progress = $rebuilder->step(4);
            $reports[] = [$progress['phase'], $progress['processed'], $progress['percent']];
        }

        // objects: 4, 6 (end of the phase); stubs: 10, 12
        $this->assertSame([['objects', 4, 33], ['stubs', 6, 50], ['stubs', 10, 83], [null, 12, 100]], $reports);
        $this->assertFalse($rebuilder->isRunning());
        $this->assertCount(6, $this->stubFiles($this->storage));
    }

    public function testIndexStaysUsableAndAnotherProcessContinues(): void
    {
        $uuids = [];
        for ($i = 0; $i < 6; $i++) {
            $uuids[] = $this->storage->store(new ArrayObject([$i]));
        }

        $first = $this->createRebuilder($this->storage);
        $first->start();
        $first->step(3);

        // meanwhile, the class index is complete and the storage writable
        $this->assertCount(6, iterator_to_array($this->storage->list(ArrayObject::class), false));
        $this->assertFalse($this->storage->getStateHandler()->safeModeEnabled());
        $added = $this->storage->store(new ArrayObject(['added']));

        $second = $this->createRebuilder(new ObjectStorage($this->storage->getStorageDir()));
        $this->assertTrue($second->isRunning());
        $progress = $second->rebuildStubs();

        $this->assertTrue($progress['done']);
        $list = iterator_to_array($this->storage->list(ArrayObject::class), false);
        sort($list);
        $expected = [...$uuids, $added];
        sort($expected);
        $this->assertSame($expected, $list, 'an object stored meanwhile keeps its stub');
    }

    public function testObjectWithoutClassIsMovedAside(): void
    {
        $uuid = $this->storage->store(new ArrayObject([1]));
        unlink($this->storage->getFilePathMetadata($uuid));

        $progress = $this->createRebuilder(new ObjectStorage($this->storage->getStorageDir()))->rebuildStubs();

        $this->assertSame(1, $progress['corrupted']);
        $this->assertFileDoesNotExist($this->storage->getFilePathData($uuid));
        $this->assertFileExists($this->storage->getStorageDir() . '/corrupted/' . basename($this->storage->getFilePathData($uuid)));
    }

    public function testStateAndSnapshotsAreObjectsOfAnInternalStorage(): void
    {
        $this->storage->store(new ArrayObject([1]));
        $rebuilder = $this->createRebuilder($this->storage);
        $this->assertFalse($rebuilder->isRunning());
        $this->assertDirectoryDoesNotExist($this->storage->getStorageDir() . '/maintenance', 'looking creates nothing');

        $rebuilder->start();

        $internal = $rebuilder->getInternalStorage();
        $this->assertSame($this->storage->getStorageDir() . DIRECTORY_SEPARATOR . 'maintenance' . DIRECTORY_SEPARATOR . 'internal', $internal->getStorageDir());
        $this->assertCount(1, iterator_to_array($internal->list(\melia\ObjectStorage\Util\Maintenance\TaskState::class), false));
        $this->assertCount(2, iterator_to_array($internal->list(\melia\ObjectStorage\Util\UuidTrie::class), false), 'objects and stubs, one record each');
        $this->assertSame([], array_values(array_diff(scandir($this->storage->getStorageDir() . '/maintenance'), ['.', '..', 'internal'])), 'no other files');
        $this->assertSame([ArrayObject::class], array_values($this->storage->getClassNames()), 'the indexed storage doesn\'t see them');

        $rebuilder->rebuildStubs();

        $this->assertFalse($rebuilder->isRunning());
        $this->assertSame([], iterator_to_array($internal->list(), false), 'removed when complete');
    }

    public function testRebuildCantBeStartedTwice(): void
    {
        $this->storage->store(new ArrayObject([1]));
        $rebuilder = $this->createRebuilder($this->storage);
        $rebuilder->start();

        $this->expectException(RuntimeException::class);
        $rebuilder->start();
    }

    public function testEmptyStorage(): void
    {
        $progress = $this->storage->rebuildStubs();

        $this->assertTrue($progress['done']);
        $this->assertSame(0, $progress['total']);
    }
}
