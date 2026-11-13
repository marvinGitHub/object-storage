<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Migration\AttributeToReference;
use melia\ObjectStorage\Query\Oql;
use stdClass;

/**
 * Every way of writing the data of an object writes the checksum of that data: OQL (INSERT, UPDATE, DROP ATTRIBUTE),
 * linking (AttributeToReference, as the viewer links) and store(), also over metadata without a checksum.
 */
class ChecksumRewriteTest extends TestCase
{
    /** the data on disk matches the checksum of the metadata on disk, and the object can be read */
    private function assertChecksumMatches(string $uuid): void
    {
        $data = (string)file_get_contents($this->storage->getFilePathData($uuid));
        $metadata = json_decode((string)file_get_contents($this->storage->getFilePathMetadata($uuid)), true);
        $this->assertSame(hash($metadata['checksumAlgorithm'], $data), $metadata['checksum'], "checksum of $uuid");
        $this->storage->clearCache();
        $this->assertSame($data, $this->storage->readRaw($uuid)->data);
    }

    private function checksumOf(string $uuid): string
    {
        return json_decode((string)file_get_contents($this->storage->getFilePathMetadata($uuid)), true)['checksum'];
    }

    public function testOqlWritesTheChecksumOfTheNewData(): void
    {
        $oql = new Oql($this->storage);
        $uuid = $oql->query("INSERT INTO stdClass (name, legacy) VALUES ('Box', 'old')")->column(0)[0];
        $this->assertChecksumMatches($uuid);

        $before = $this->checksumOf($uuid);
        $oql->query("UPDATE stdClass SET name = 'Crate' WHERE UUID = ?", [$uuid]);
        $this->assertNotSame($before, $this->checksumOf($uuid));
        $this->assertChecksumMatches($uuid);

        $before = $this->checksumOf($uuid);
        $oql->query('ALTER CLASS stdClass DROP ATTRIBUTE legacy');
        $this->assertNotSame($before, $this->checksumOf($uuid));
        $this->assertChecksumMatches($uuid);
    }

    public function testLinkingWritesTheChecksumOfTheNewData(): void
    {
        $friend = new stdClass();
        $friend->name = 'Ben';
        $friendUuid = $this->storage->store($friend);
        $anna = new stdClass();
        $anna->name = 'Anna';
        $anna->friend = $friendUuid; // a UUID as text
        $uuid = $this->storage->store($anna);
        $before = $this->checksumOf($uuid);

        $migration = new AttributeToReference('friend');
        $migration->setStorage($this->storage);
        $this->assertTrue($migration->apply($this->storage->load($uuid), $uuid));

        $this->assertNotSame($before, $this->checksumOf($uuid));
        $this->assertChecksumMatches($uuid);
    }

    public function testStoringOverMetadataWithoutAChecksumWritesIt(): void
    {
        $note = new stdClass();
        $note->text = 'a';
        $uuid = $this->storage->store($note);
        $file = $this->storage->getFilePathMetadata($uuid);
        $metadata = json_decode((string)file_get_contents($file), true);
        unset($metadata['checksum']);
        file_put_contents($file, json_encode($metadata));
        $this->storage->clearCache();

        // the same data again: written, as the metadata doesn't prove it
        $note = new stdClass();
        $note->text = 'a';
        $this->storage->store($note, $uuid);

        $this->assertChecksumMatches($uuid);
    }
}
