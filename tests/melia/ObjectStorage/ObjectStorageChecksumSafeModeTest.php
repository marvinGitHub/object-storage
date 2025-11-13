<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Exception\ChecksumMismatchException;
use melia\ObjectStorage\Exception\InvalidFileFormatException;
use melia\ObjectStorage\Exception\ObjectLoadingFailureException;
use melia\ObjectStorage\Exception\SerializationFailureException;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\State\StateHandler;
use melia\ObjectStorage\Metadata\Metadata;
use ReflectionClass;

/**
 * These tests isolate safe-mode behavior when checksum mismatches happen,
 * and when metadata or JSON decoding fail.
 */
final class ObjectStorageChecksumSafeModeTest extends TestCase
{
    public function testSafeModeEnabledOnChecksumMismatch(): void
    {
        // Prepare a dummy class and UUID
        $uuid = $this->storage->getNextAvailableUuid();

        // Create minimal metadata with checksum that won't match the data file
        $metadata = new Metadata();
        $metadata->setUuid($uuid);
        $metadata->setClassName(DummyClassForChecksumTest::class);
        $metadata->setVersion(1);
        $metadata->setTimestampCreation(microtime(true));
        $metadata->setChecksum('abcdef0123456789abcdef0123456789'); // bogus checksum
        $metadataPath = $this->storage->getFilePathMetadata($uuid);
        file_put_contents($metadataPath, json_encode($metadata, JSON_UNESCAPED_SLASHES));

        // Create object data file with content that will produce a different checksum
        $objectPath = $this->storage->getFilePathData($uuid);
        file_put_contents($objectPath, json_encode(['a' => 1], JSON_UNESCAPED_SLASHES));

        try {
            $this->storage->load($uuid);
        } catch (ObjectLoadingFailureException $e) {
            $this->assertInstanceOf(ChecksumMismatchException::class, $e->getPrevious());
        } finally {
            // After mismatch, safe mode must be enabled
            $this->assertTrue($this->getStateHandler($this->storage)->safeModeEnabled(), 'Safe mode should be enabled after checksum mismatch.');
        }
    }

    public function testSafeModeEnabledWhenMetadataCannotBeLoaded(): void
    {
        $uuid = '98e9eb5d-b1dc-4f12-997e-e56c021320bb';

        // No metadata file is written to simulate load failure
        // Create object file so load attempts to proceed after metadata (which will fail first)
        file_put_contents($this->storage->getFilePathData($uuid), json_encode(['x' => 2], JSON_UNESCAPED_SLASHES));

        $this->expectException(ObjectLoadingFailureException::class);

        try {
            $this->storage->load($uuid);
        } finally {
            $this->assertTrue($this->getStateHandler($this->storage)->safeModeEnabled(), 'Safe mode should be enabled when metadata is missing/invalid.');
        }
    }

    public function testSafeModeEnabledOnJsonDecodingFailureWithoutChecksum(): void
    {
        $uuid = '33333333-3333-3333-3333-333333333333';

        // Write metadata without checksum validation path (getRegisteredClassnames path uses loadFromJsonFile without checksum)
        $classnamesFile = $this->storage->getStorageDir() . DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR . 'classnames.json';
        if (!is_dir(dirname($classnamesFile))) {
            mkdir(dirname($classnamesFile), 0777, true);
        }

        // Write invalid JSON to trigger JSON decoding failure
        file_put_contents($classnamesFile, '{invalid json');

        $this->expectException(SerializationFailureException::class);

        try {
            // This call uses loadFromJsonFile() without passing checksum and will enable safe mode on JSON decode failure
            $this->storage->getRegisteredClassnames();
        } finally {
            $this->assertTrue($this->getStateHandler($this->storage)->safeModeEnabled(), 'Safe mode should be enabled after JSON decoding failure.');
        }
    }

    /**
     * Extract the StateHandler from ObjectStorage via reflection since it is protected.
     */
    private function getStateHandler(ObjectStorage $storage): StateHandler
    {
        $ref = new ReflectionClass($storage);
        $prop = $ref->getProperty('stateHandler');
        $prop->setAccessible(true);
        return $prop->getValue($storage);
    }
}

/**
 * Minimal class used for instantiation during load.
 */
final class DummyClassForChecksumTest
{
    public $a = null;
}