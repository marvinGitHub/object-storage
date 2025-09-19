<?php

namespace Tests\melia\ObjectStorage;

class ObjectStorageClassAliasTest extends TestCase
{
    public function testClassAlias()
    {
        $dir = $this->reserveRandomStorageDirectory();
        $uuid = '877d51df-aebd-4807-8701-95e007e9b701';
        do {
            $unknownClassname = uniqid('SomeUnknownClass');
        } while (class_exists($unknownClassname));

        $this->assertFalse(class_exists($unknownClassname));

        file_put_contents(sprintf('%s%s%s.metadata', $dir, DIRECTORY_SEPARATOR, $uuid), sprintf('{"timestamp":1756892960,"className":"%s","uuid":"099c84a5-78cb-4e30-a15f-2b4ef7ec176d","version":"1.0","checksum":"b029245bf19000f67e92da851c959928"}', $unknownClassname));
        file_put_contents(sprintf('%s%s%s.obj', $dir, DIRECTORY_SEPARATOR, $uuid), '{"name":"Lazy-B"}');

        $storage = new \melia\ObjectStorage\ObjectStorage($dir);
        $this->assertTrue($storage->exists($uuid));

        $loaded = $storage->load($uuid);
        $this->assertInstanceOf($unknownClassname, $loaded);
        $this->assertEquals('Lazy-B', $loaded->name);
        $this->assertTrue(class_exists($unknownClassname));
    }
}