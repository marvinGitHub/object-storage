<?php

namespace Tests\melia\ObjectStorage\Storage;

use Closure;
use melia\ObjectStorage\Cache\InMemoryCache;
use melia\ObjectStorage\Constraint\ConstraintChecker;
use melia\ObjectStorage\Constraint\ConstraintInterface;
use melia\ObjectStorage\Constraint\ConstraintRegistry;
use melia\ObjectStorage\Exception\ConstraintViolationException;
use melia\ObjectStorage\Exception\RevisionConflictException;
use melia\ObjectStorage\File\Files;
use melia\ObjectStorage\File\IO\RealAdapter;
use melia\ObjectStorage\File\JsonFiles;
use melia\ObjectStorage\Locking\ObjectLocks;
use melia\ObjectStorage\Locking\RetainedLocks;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\State\StateHandler;
use melia\ObjectStorage\Storage\ClassNameRegistry;
use melia\ObjectStorage\Storage\JsonStore;
use melia\ObjectStorage\Storage\MetadataStore;
use melia\ObjectStorage\Storage\ObjectCache;
use melia\ObjectStorage\Storage\ObjectFiles;
use melia\ObjectStorage\Storage\ObjectPaths;
use melia\ObjectStorage\Storage\ObjectReader;
use melia\ObjectStorage\Storage\ObjectSession;
use melia\ObjectStorage\Storage\ObjectWriter;
use melia\ObjectStorage\Storage\StubIndex;
use melia\ObjectStorage\Strategy\Standard;
use Tests\melia\ObjectStorage\TestCase;

class ObjectWriterTest extends TestCase
{
    private const string UUID = 'ab12cd34-0000-4000-8000-000000000001';

    private string $directory;
    private ObjectPaths $paths;
    private Standard $strategy;
    private ObjectSession $session;
    private ConstraintRegistry $constraints;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = $this->createTemporaryDirectory();
        $this->storageDirs[] = $this->directory;
        $this->strategy = new Standard();
        $this->paths = new ObjectPaths($this->directory, $this->strategy->getShardDepth());
        $this->session = new ObjectSession();
        $this->constraints = new ConstraintRegistry();
    }

    /**
     * @return array{ObjectWriter, MetadataStore, StubIndex}
     */
    private function components(): array
    {
        $io = new RealAdapter();
        $files = new Files(sync: false);
        $files->setIOAdapter($io);
        $json = new JsonStore(new JsonFiles($files));
        $metadata = new MetadataStore($this->paths, $io, $json);
        $stateHandler = new StateHandler($this->directory);
        $objectFiles = new ObjectFiles($this->paths, $files, $io, $metadata, $this->strategy, $stateHandler);
        $stubs = new StubIndex($this->paths, $io, new ClassNameRegistry($this->paths, $io, $json));
        $cache = new ObjectCache(new InMemoryCache());
        $reader = new ObjectReader(new ObjectStorage($this->directory), $objectFiles, $this->session, $cache, $this->strategy);

        $writer = new ObjectWriter(
            $objectFiles, $metadata, $stubs, $cache, new ObjectLocks(null, new RetainedLocks()), $this->session, $reader,
            new ConstraintChecker($this->constraints, $this->strategy), $this->strategy,
        );

        return [$writer, $metadata, $stubs];
    }

    public function testAnObjectIsWrittenWithItsMetadataAndStub(): void
    {
        [$writer, $metadata, $stubs] = $this->components();
        $object = new WriterTestNode('root');

        $writer->write($object, self::UUID, null, null);

        $stored = $metadata->reload(self::UUID);
        $this->assertNotNull($stored);
        $this->assertSame(WriterTestNode::class, $stored->getClassName());
        $this->assertSame(1, $stored->getRevision());
        $this->assertSame(1, $this->session->revisionOf($object));
        $this->assertTrue($stubs->hasClass(WriterTestNode::class));
        $this->assertSame('{"child":null,"name":"root"}', file_get_contents($this->paths->dataFile(self::UUID)));
    }

    public function testUnchangedDataIsNotWrittenAgain(): void
    {
        [$writer, $metadata] = $this->components();
        $object = new WriterTestNode('root');
        $writer->write($object, self::UUID, null, null);

        $writer->write($object, self::UUID, null, null);

        $this->assertSame(1, $metadata->reload(self::UUID)?->getRevision(), 'the same data: no new revision');
    }

    public function testReferencedObjectsAreWrittenFirst(): void
    {
        [$writer] = $this->components();
        $object = new WriterTestNode('root', new WriterTestNode('child'));

        $writer->write($object, self::UUID, null, null);

        $childUuid = $this->session->uuidOf($object->child);
        $this->assertNotNull($childUuid);
        $this->assertFileExists($this->paths->dataFile($childUuid));
        $this->assertStringContainsString('"__reference":"' . $childUuid . '"', (string)file_get_contents($this->paths->dataFile(self::UUID)));
    }

    public function testAnObjectChangedMeanwhileIsAConflictWithOptimisticLocking(): void
    {
        $this->strategy->enableOptimisticLocking();
        [$writer] = $this->components();
        $object = new WriterTestNode('root');
        $writer->write($object, self::UUID, null, null);

        // stored meanwhile by another storage instance, which has a session of its own
        $this->session = new ObjectSession();
        [$other] = $this->components();
        $other->write(new WriterTestNode('changed'), self::UUID, null, null);

        $object->name = 'mine';
        $this->expectException(RevisionConflictException::class);
        $writer->write($object, self::UUID, null, null);
    }

    public function testAViolatedConstraintPreventsTheWrite(): void
    {
        $this->constraints->add(new class implements ConstraintInterface {
            public function check(string $uuid, string $className, array $data, string $reservedReferenceName): ?Closure
            {
                throw new ConstraintViolationException('names must be unique');
            }
        });
        [$writer] = $this->components();

        try {
            $writer->write(new WriterTestNode('root'), self::UUID, null, null);
            $this->fail('Expected ConstraintViolationException');
        } catch (ConstraintViolationException) {
        }

        $this->assertFileDoesNotExist($this->paths->dataFile(self::UUID));
    }
}

class WriterTestNode
{
    public function __construct(public string $name = '', public ?WriterTestNode $child = null)
    {
    }
}
