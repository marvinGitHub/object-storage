<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\Exception\ObjectLoadingFailureException;
use melia\ObjectStorage\Exception\ObjectSavingFailureException;
use melia\ObjectStorage\File\Files;

/**
 * A file that exists but can't be read (e.g. held open by another program) is an error, not a missing object:
 * callers create an object anew if none is stored, which would overwrite the existing one.
 */
final class ObjectStorageReadFailureTest extends TestCase
{
    /**
     * @param string $suffix The end of the paths that can't be read.
     */
    private function failReading(string $suffix): void
    {
        $this->storage->setFiles(new class($suffix) extends Files {
            public function __construct(private readonly string $suffix)
            {
                parent::__construct();
            }

            public function read(string $path): ?string
            {
                if (str_ends_with($path, $this->suffix)) {
                    throw new IOException('Unable to read file: ' . $path);
                }
                return parent::read($path);
            }
        });
    }

    public function testUnreadableDataIsNotReportedAsMissing(): void
    {
        $uuid = $this->storage->store(new ChildObject('child', 1));
        $this->storage->clearCache();
        $this->failReading('.obj');

        try {
            $this->storage->load($uuid, exclusive: true);
            $this->fail('An unreadable object must not be loaded as null');
        } catch (ObjectLoadingFailureException $e) {
            $this->assertInstanceOf(IOException::class, $e->getPrevious());
        }
        $this->assertFalse($this->storage->getStateHandler()->safeModeEnabled());
    }

    public function testMissingObjectIsStillNull(): void
    {
        $uuid = $this->storage->store(new ChildObject('child', 1));
        $this->storage->delete($uuid);

        $this->assertNull($this->storage->load($uuid));
    }

    public function testUnreadableClassRegistryIsNotOverwritten(): void
    {
        $this->storage->store(new ChildObject('child', 1));
        $registry = $this->storage->getStubDirectory() . DIRECTORY_SEPARATOR . 'classnames.json';
        $this->assertFileExists($registry);
        $before = file_get_contents($registry);

        // another storage instance, so the registry isn't cached in memory yet
        $other = new \melia\ObjectStorage\ObjectStorage($this->storage->getStorageDir());
        $this->storage = $other;
        $this->failReading('classnames.json');

        try {
            $other->store(new TestObject());
            $this->fail('Storing a new class must fail while the registry can\'t be read');
        } catch (ObjectSavingFailureException) {
        }

        $this->assertSame($before, file_get_contents($registry));
    }
}
