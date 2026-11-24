<?php

namespace Tests\melia\ObjectStorage\Storage;

use melia\ObjectStorage\Storage\ObjectPaths;
use PHPUnit\Framework\TestCase;

class ObjectPathsTest extends TestCase
{
    private const string UUID = 'ab12cd34-0000-4000-8000-000000000000';

    public function testObjectFilesAreShardedByTheLeadingCharactersOfTheUuid(): void
    {
        $paths = new ObjectPaths('/data', 2);
        $directory = '/data' . DIRECTORY_SEPARATOR . 'shards' . DIRECTORY_SEPARATOR . 'a' . DIRECTORY_SEPARATOR . 'b' . DIRECTORY_SEPARATOR;

        $this->assertSame($directory, $paths->shardedDirectory(self::UUID));
        $this->assertSame($directory . self::UUID . '.obj', $paths->dataFile(self::UUID));
        $this->assertSame($directory . self::UUID . '.metadata', $paths->metadataFile(self::UUID));
        // the data before the metadata holding its checksum
        $this->assertSame([$paths->dataFile(self::UUID), $paths->metadataFile(self::UUID)], $paths->objectFiles(self::UUID));
    }

    public function testDepthZeroKeepsTheFilesInTheShardDirectory(): void
    {
        $paths = new ObjectPaths('/data', 0);

        // as before the split: the path has a doubled separator there ("shards//<uuid>.obj"), the same file
        $this->assertSame('/data' . DIRECTORY_SEPARATOR . 'shards', dirname($paths->dataFile(self::UUID)));
        $this->assertSame(self::UUID . '.obj', basename($paths->dataFile(self::UUID)));
    }

    public function testHyphensDontCountAsLevels(): void
    {
        $paths = new ObjectPaths('/data', 10);
        $levels = explode(DIRECTORY_SEPARATOR, trim(substr($paths->shardedDirectory(self::UUID), strlen('/data' . DIRECTORY_SEPARATOR . 'shards')), DIRECTORY_SEPARATOR));

        $this->assertSame(['a', 'b', '1', '2', 'c', 'd', '3', '4', '0', '0'], $levels);
    }

    public function testTheDepthIsCapped(): void
    {
        $capped = new ObjectPaths('/data', 100);

        $this->assertSame((new ObjectPaths('/data', ObjectPaths::MAX_SHARD_DEPTH))->dataFile(self::UUID), $capped->dataFile(self::UUID));
        $this->assertTrue($capped->isFor('/data', 50), 'any depth beyond the cap gives the same paths');
    }

    public function testStubsAreGroupedByAHashOfTheClass(): void
    {
        $paths = new ObjectPaths('/data', 2);
        $classDirectory = '/data' . DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR . md5('App\Customer');

        $this->assertSame($classDirectory, $paths->classStubDirectory('App\Customer'));
        $this->assertSame($classDirectory . DIRECTORY_SEPARATOR . self::UUID . '.stub', $paths->stubFile('App\Customer', self::UUID));
        $this->assertSame('/data' . DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR . 'classnames.json', $paths->classNamesFile());
    }

    public function testDirectoriesOfLocksAndDamagedObjects(): void
    {
        $paths = new ObjectPaths('/data', 2);

        $this->assertSame('/data' . DIRECTORY_SEPARATOR . 'locks', $paths->lockDirectory());
        $this->assertSame('/data' . DIRECTORY_SEPARATOR . 'corrupted', $paths->corruptedDirectory());
    }

    public function testPathsAreValidOnlyForTheirDirectoryAndDepth(): void
    {
        $paths = new ObjectPaths('/data', 2);

        $this->assertTrue($paths->isFor('/data', 2));
        $this->assertFalse($paths->isFor('/other', 2));
        $this->assertFalse($paths->isFor('/data', 3), 'a changed shard depth needs new paths');
    }
}
