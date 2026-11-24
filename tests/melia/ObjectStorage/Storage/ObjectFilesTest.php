<?php

namespace Tests\melia\ObjectStorage\Storage;

use melia\ObjectStorage\Cache\InMemoryCache;
use melia\ObjectStorage\Exception\ChecksumMismatchException;
use melia\ObjectStorage\Exception\InvalidFileFormatException;
use melia\ObjectStorage\File\Files;
use melia\ObjectStorage\File\IO\RealAdapter;
use melia\ObjectStorage\File\JsonFiles;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\State\StateHandler;
use melia\ObjectStorage\Storage\JsonStore;
use melia\ObjectStorage\Storage\MetadataStore;
use melia\ObjectStorage\Storage\ObjectFiles;
use melia\ObjectStorage\Storage\ObjectPaths;
use melia\ObjectStorage\Strategy\Standard;
use Tests\melia\ObjectStorage\TestCase;

class ObjectFilesTest extends TestCase
{
    private const string UUID = 'ab12cd34-0000-4000-8000-000000000001';

    private string $directory;
    private ObjectPaths $paths;
    private InMemoryCache $cache;
    private StateHandler $stateHandler;
    private Standard $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = $this->createTemporaryDirectory();
        $this->storageDirs[] = $this->directory;
        $this->paths = new ObjectPaths($this->directory, 2);
        $this->cache = new InMemoryCache();
        $this->stateHandler = new StateHandler($this->directory);
        $this->strategy = new Standard();
    }

    private function objectFiles(): ObjectFiles
    {
        $io = new RealAdapter();
        $files = new Files(sync: false);
        $files->setIOAdapter($io);
        $metadata = new MetadataStore($this->paths, $io, new JsonStore(new JsonFiles($files)), $this->cache);

        return new ObjectFiles($this->paths, $files, $io, $metadata, $this->strategy, $this->stateHandler);
    }

    private static function metadata(string $data, int $revision = 1): Metadata
    {
        return Metadata::createFromArray([
            'uuid' => self::UUID,
            'className' => 'App\Customer',
            'checksum' => ObjectFiles::checksum($data, 'sha256'),
            'checksumAlgorithm' => 'sha256',
            'revision' => $revision,
            'timestampCreation' => 1000.0,
            'timestampModification' => 2000.0,
        ]);
    }

    public function testNothingStored(): void
    {
        $this->assertNull($this->objectFiles()->read(self::UUID));
    }

    public function testDataIsReadWithTheMetadataItWasWrittenWith(): void
    {
        $this->objectFiles()->write(self::UUID, '{"a":1}', self::metadata('{"a":1}'));

        [$raw, $metadata, $file] = $this->objectFiles()->read(self::UUID);

        $this->assertSame('{"a":1}', $raw);
        $this->assertSame(1, $metadata->getRevision());
        $this->assertSame($this->paths->dataFile(self::UUID), $file);
    }

    public function testOutdatedMetadataInTheCacheIsReadAgain(): void
    {
        $objectFiles = $this->objectFiles();
        $objectFiles->write(self::UUID, '{"a":1}', self::metadata('{"a":1}'));
        $this->cache->set(self::UUID, self::metadata('{"a":0}', 0)); // written meanwhile by another process

        [, $metadata] = $objectFiles->read(self::UUID);

        $this->assertSame(1, $metadata->getRevision());
    }

    public function testDataNotMatchingItsChecksumEnablesSafeMode(): void
    {
        $this->objectFiles()->write(self::UUID, '{"a":1}', self::metadata('{"a":1}'));
        file_put_contents($this->paths->dataFile(self::UUID), '{"a":2}');

        try {
            $this->objectFiles()->read(self::UUID, safeModeOnCorruption: false);
            $this->fail('Expected ChecksumMismatchException');
        } catch (ChecksumMismatchException) {
        }
        $this->assertFalse($this->stateHandler->safeModeEnabled(), 'only reported');

        $this->expectException(ChecksumMismatchException::class);
        try {
            $this->objectFiles()->read(self::UUID);
        } finally {
            $this->assertTrue($this->stateHandler->safeModeEnabled());
        }
    }

    public function testChecksumsAreNotVerifiedIfDisabled(): void
    {
        $this->objectFiles()->write(self::UUID, '{"a":1}', self::metadata('{"a":1}'));
        file_put_contents($this->paths->dataFile(self::UUID), '{"a":2}');
        $this->strategy->disableChecksumValidation();

        $this->assertSame('{"a":2}', $this->objectFiles()->read(self::UUID)[0]);
    }

    public function testDataWithoutMetadataIsCorrupted(): void
    {
        $this->objectFiles()->write(self::UUID, '{"a":1}', self::metadata('{"a":1}'));
        unlink($this->paths->metadataFile(self::UUID));

        $this->expectException(InvalidFileFormatException::class);
        try {
            $this->objectFiles()->read(self::UUID);
        } finally {
            $this->assertTrue($this->stateHandler->safeModeEnabled());
        }
    }

    public function testNothingToRecover(): void
    {
        $this->objectFiles()->write(self::UUID, '{"a":1}', self::metadata('{"a":1}'));

        $this->assertFalse($this->objectFiles()->recover(self::UUID));
    }
}
