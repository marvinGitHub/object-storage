<?php

namespace Tests\melia\ObjectStorage\Storage;

use melia\ObjectStorage\Event\Dispatcher;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\File\Files;
use melia\ObjectStorage\File\IO\RealAdapter;
use melia\ObjectStorage\File\JsonFiles;
use melia\ObjectStorage\Storage\ClassNameRegistry;
use melia\ObjectStorage\Storage\JsonStore;
use melia\ObjectStorage\Storage\ObjectPaths;
use melia\ObjectStorage\Storage\StubIndex;
use Tests\melia\ObjectStorage\TestCase;

class StubIndexTest extends TestCase
{
    private const string FIRST = 'ab12cd34-0000-4000-8000-000000000001';
    private const string SECOND = 'ab12cd34-0000-4000-8000-000000000002';

    private ObjectPaths $paths;
    private ClassNameRegistry $registry;
    private Dispatcher $dispatcher;
    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $directory = $this->createTemporaryDirectory();
        $this->storageDirs[] = $directory;
        $this->paths = new ObjectPaths($directory, 2);

        $io = new RealAdapter();
        $files = new Files(sync: false);
        $files->setIOAdapter($io);
        $this->registry = new ClassNameRegistry($this->paths, $io, new JsonStore(new JsonFiles($files)));

        $this->dispatcher = new Dispatcher();
        foreach ([Events::STUB_CREATED, Events::STUB_REMOVED] as $event) {
            $this->dispatcher->addListener($event, function () use ($event): void {
                $this->events[] = $event;
            });
        }
    }

    private function index(): StubIndex
    {
        return new StubIndex($this->paths, new RealAdapter(), $this->registry, $this->dispatcher);
    }

    public function testAStubIndexesTheObjectAndRegistersItsClass(): void
    {
        $index = $this->index();
        $this->assertFalse($index->hasClass('App\Customer'));

        $index->add('App\Customer', self::FIRST);
        $index->add('App\Customer', self::SECOND);

        $this->assertTrue($index->hasClass('App\Customer'));
        $this->assertFileExists($this->paths->stubFile('App\Customer', self::FIRST));
        $this->assertEqualsCanonicalizing([self::FIRST, self::SECOND], array_values(iterator_to_array($index->uuidsOf('App\Customer'))));
        $this->assertSame(['App\Customer' => 'App\Customer'], $this->registry->all());
        $this->assertSame([Events::STUB_CREATED, Events::STUB_CREATED], $this->events);
    }

    public function testAddingAnExistingStubOnlyRegistersTheClass(): void
    {
        $index = $this->index();
        $index->add('App\Customer', self::FIRST);
        // the registry was lost, e.g. while rebuilding the stubs
        unlink($this->paths->classNamesFile());
        $this->registry->forget();

        $index->add('App\Customer', self::FIRST);

        $this->assertSame(['App\Customer' => 'App\Customer'], $this->registry->all());
        $this->assertSame([Events::STUB_CREATED], $this->events, 'the stub is created once');
    }

    public function testRemovingStubs(): void
    {
        $index = $this->index();
        $index->add('App\Customer', self::FIRST);

        $index->remove('App\Customer', self::FIRST);
        $index->remove('App\Customer', self::SECOND); // there is none

        $this->assertFileDoesNotExist($this->paths->stubFile('App\Customer', self::FIRST));
        $this->assertSame([], iterator_to_array($index->uuidsOf('App\Customer')));
        $this->assertSame([Events::STUB_CREATED, Events::STUB_REMOVED, Events::STUB_REMOVED], $this->events);
    }

    public function testTheDirectoriesOfStubsArePerClass(): void
    {
        $index = $this->index();
        $index->add('App\Customer', self::FIRST);
        $index->add('App\Order', self::SECOND);

        $this->assertEqualsCanonicalizing(
            [$this->paths->classStubDirectory('App\Customer'), $this->paths->classStubDirectory('App\Order')],
            $index->classDirectories()
        );
    }
}
