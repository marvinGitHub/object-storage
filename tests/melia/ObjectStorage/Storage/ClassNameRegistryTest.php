<?php

namespace Tests\melia\ObjectStorage\Storage;

use melia\ObjectStorage\Exception\ClassnameRegistrationFailureException;
use melia\ObjectStorage\Exception\CorruptedFileException;
use melia\ObjectStorage\File\Files;
use melia\ObjectStorage\File\IO\RealAdapter;
use melia\ObjectStorage\File\JsonFiles;
use melia\ObjectStorage\Locking\Backends\SymfonyLockAdapter;
use melia\ObjectStorage\Locking\LockAdapterInterface;
use melia\ObjectStorage\Storage\ClassNameRegistry;
use melia\ObjectStorage\Storage\JsonStore;
use melia\ObjectStorage\Storage\ObjectPaths;
use Tests\melia\ObjectStorage\TestCase;

class ClassNameRegistryTest extends TestCase
{
    private string $directory;
    private ObjectPaths $paths;
    private LockAdapterInterface $locks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = $this->createTemporaryDirectory();
        $this->storageDirs[] = $this->directory;
        $this->paths = new ObjectPaths($this->directory, 2);
        $this->locks = SymfonyLockAdapter::createFileLockAdapter($this->paths->lockDirectory());
    }

    private function registry(): ClassNameRegistry
    {
        $io = new RealAdapter();
        $files = new Files(sync: false);
        $files->setIOAdapter($io);

        return new ClassNameRegistry($this->paths, $io, new JsonStore(new JsonFiles($files)), $this->locks);
    }

    public function testEmptyWithoutARegistryFile(): void
    {
        $this->assertSame([], $this->registry()->all());
        $this->assertFileDoesNotExist($this->paths->classNamesFile());
    }

    public function testRegisteredClassesAreWrittenAndRead(): void
    {
        $this->registry()->register('App\Customer');

        $this->assertSame(['App\Customer' => 'App\Customer'], $this->registry()->all());
        $this->assertSame(['App\Customer' => 'App\Customer'], json_decode((string)file_get_contents($this->paths->classNamesFile()), true));
    }

    public function testClassesRegisteredByOthersMeanwhileAreKept(): void
    {
        $first = $this->registry();
        $this->assertSame([], $first->all()); // read and kept in memory

        $this->registry()->register('App\Order'); // another process
        $first->register('App\Customer');

        $this->assertEqualsCanonicalizing(['App\Order', 'App\Customer'], array_values($this->registry()->all()));
    }

    public function testADamagedRegistryIsNeverWrittenOver(): void
    {
        mkdir($this->paths->stubDirectory(), 0777, true);
        file_put_contents($this->paths->classNamesFile(), '{damaged');

        // a damaged registry is a damaged file of the storage (the storage enables safe mode for it)
        try {
            $this->registry()->register('App\Customer');
            $this->fail('Expected CorruptedFileException');
        } catch (CorruptedFileException) {
        }

        $this->assertSame('{damaged', file_get_contents($this->paths->classNamesFile()));
    }

    public function testForgetReadsTheRegistryAgain(): void
    {
        $registry = $this->registry();
        $this->assertSame([], $registry->all());

        $this->registry()->register('App\Customer');
        $this->assertSame([], $registry->all(), 'kept in memory');

        $registry->forget();
        $this->assertSame(['App\Customer' => 'App\Customer'], $registry->all());
    }

    public function testRebuildReplacesTheRegistrySortedUnderItsLock(): void
    {
        $registry = $this->registry();
        $registry->register('App\Gone');

        $rebuilt = $registry->rebuild(function (): array {
            $this->assertTrue($this->locks->hasActiveExclusiveLock(ClassNameRegistry::LOCK), 'the class names are found under the lock');
            return ['App\Order' => 'App\Order', 'App\Customer' => 'App\Customer'];
        });

        $this->assertSame(['App\Customer' => 'App\Customer', 'App\Order' => 'App\Order'], $rebuilt);
        $this->assertSame($rebuilt, $this->registry()->all());
        $this->assertFalse($this->locks->hasActiveExclusiveLock(ClassNameRegistry::LOCK), 'released afterwards');
    }

    public function testAFailingRebuildKeepsTheRegistry(): void
    {
        $registry = $this->registry();
        $registry->register('App\Customer');

        try {
            $registry->rebuild(static fn(): array => throw new \RuntimeException('stubs unreadable'));
            $this->fail('Expected ClassnameRegistrationFailureException');
        } catch (ClassnameRegistrationFailureException) {
        }

        $this->assertSame(['App\Customer' => 'App\Customer'], $this->registry()->all());
        $this->assertFalse($this->locks->hasActiveExclusiveLock(ClassNameRegistry::LOCK));
    }
}
