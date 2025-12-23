<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\ObjectStorage;

class ObjectStorageClassAliasTest extends TestCase
{
    public function testClassAlias()
    {
        $dir = $this->reserveRandomDirectory();
        $uuid = '877d51df-aebd-4807-8701-95e007e9b701';
        do {
            $unknownClassname = uniqid('SomeUnknownClass');
        } while (class_exists($unknownClassname));

        $this->assertFalse(class_exists($unknownClassname));

        $storage = new ObjectStorage($dir);

        file_put_contents($storage->getFilePathMetadata($uuid), sprintf('{"timestamp":1756892960,"className":"%s","uuid":"%s","version":"1.0","checksum":"671130ff","checksumAlgorithm":"crc32b"}', $unknownClassname, $uuid));
        file_put_contents($storage->getFilePathData($uuid), '{"name":"Lazy-B"}');

        $this->assertTrue($storage->exists($uuid));

        $loaded = $storage->load($uuid);
        $this->assertInstanceOf($unknownClassname, $loaded);
        $this->assertEquals('Lazy-B', $loaded->name);
        $this->assertTrue(class_exists($unknownClassname));
    }
}