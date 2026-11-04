<?php

namespace Tests\melia\ObjectStorage\Transfer;

use InvalidArgumentException;
use melia\ObjectStorage\File\Writer;
use RuntimeException;
use Throwable;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\Manager;
use melia\ObjectStorage\Search\Request;
use melia\ObjectStorage\Strategy\Policy\ChildDeletion;
use melia\ObjectStorage\Transfer\ObjectTransfer;
use Tests\melia\ObjectStorage\Search\ArrayEngine;
use Tests\melia\ObjectStorage\Search\Category;
use Tests\melia\ObjectStorage\Search\Product;
use Tests\melia\ObjectStorage\TestCase;

/**
 * Copying and moving objects between storages, as stored.
 */
class ObjectTransferTest extends TestCase
{
    private ObjectStorage $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->target = $this->createStorage();
    }

    private function transfer(): ObjectTransfer
    {
        return new ObjectTransfer($this->storage, $this->target);
    }

    public function testACopyKeepsUuidRevisionExpirationAndChecksum(): void
    {
        $uuid = $this->storage->store($apple = new Product('Apple', price: 1.5));
        $apple->price = 2; // a second revision
        $this->storage->store($apple);
        $this->storage->setLifetime($uuid, 3600);

        $result = $this->transfer()->copy([$uuid]);

        $this->assertSame([$uuid], $result->transferred);
        $this->assertTrue($this->storage->exists($uuid), 'a copy keeps the source');
        $this->assertSame(2, $this->target->getRevision($uuid));
        $this->assertEqualsWithDelta($this->storage->loadMetadata($uuid)->getTimestampExpiresAt(), $this->target->loadMetadata($uuid)->getTimestampExpiresAt(), 0.001);
        $this->assertSame($this->storage->loadMetadata($uuid)->getChecksum(), $this->target->loadMetadata($uuid)->getChecksum());
        $this->assertSame(2, $this->target->load($uuid)->price);
        $this->assertSame([Product::class], array_values($this->target->getClassNames()));
        $this->assertSame([$uuid], iterator_to_array($this->target->list(Product::class), false));
    }

    public function testReferencedObjectsAreCopiedOnRequest(): void
    {
        $uuid = $this->storage->store(new Product('Chair', category: new Category('Furniture')));
        $categoryUuid = json_decode(file_get_contents($this->storage->getFilePathData($uuid)), true)['category']['__reference'];

        $this->transfer()->copy([$uuid]);
        $this->assertFalse($this->target->exists($categoryUuid), 'without references, only the object');

        $result = $this->transfer()->copy([$uuid], withReferences: true, onConflict: ObjectTransfer::CONFLICT_OVERWRITE);
        $this->assertSame([$categoryUuid], $result->references);
        $this->assertSame('Furniture', $this->target->load($uuid)->category->title);
    }

    public function testConflicts(): void
    {
        $uuid = $this->storage->store(new Product('Apple'));
        $this->target->store(new Product('Other apple'), $uuid);

        $failed = $this->transfer()->copy([$uuid]);
        $this->assertSame([$uuid => 'exists in the target'], $failed->failed);

        $skipped = $this->transfer()->copy([$uuid], onConflict: ObjectTransfer::CONFLICT_SKIP);
        $this->assertSame([$uuid => 'exists in the target'], $skipped->skipped);
        $this->assertSame('Other apple', $this->target->load($uuid)->name);

        $this->transfer()->copy([$uuid], onConflict: ObjectTransfer::CONFLICT_OVERWRITE);
        $this->target->clearCache();
        $this->assertSame('Apple', $this->target->load($uuid)->name);
    }

    public function testMovingDeletesOnlyTheObjectInTheSource(): void
    {
        $this->storage->getStrategy()->setPolicyChildDeletion(ChildDeletion::ALWAYS);
        $uuid = $this->storage->store(new Product('Chair', category: new Category('Furniture')));
        $categoryUuid = json_decode(file_get_contents($this->storage->getFilePathData($uuid)), true)['category']['__reference'];

        $result = $this->transfer()->move([$uuid], withReferences: true);

        $this->assertSame([$uuid], $result->transferred);
        $this->assertFalse($this->storage->exists($uuid));
        $this->assertSame([], iterator_to_array($this->storage->list(Product::class), false), 'the stub is gone');
        $this->assertTrue($this->storage->exists($categoryUuid), 'referenced objects stay in the source');
        $this->assertSame('Furniture', $this->target->load($uuid)->category->title);
    }

    public function testMovingAClassWhileTheSourceChanges(): void
    {
        $uuids = [];
        for ($i = 0; $i < 5; $i++) {
            $uuids[] = $this->storage->store(new Product('Product ' . $i));
        }

        // the list is taken before moving, though moving changes what the source lists
        $result = $this->transfer()->move($this->storage->list(Product::class));

        $this->assertEqualsCanonicalizing($uuids, $result->transferred);
        $this->assertSame(0, iterator_count($this->storage->list(Product::class)));
        $this->assertSame(5, iterator_count($this->target->list(Product::class)));
    }

    public function testExpiredObjectsAreSkipped(): void
    {
        $uuid = $this->storage->store(new Product('Apple'));
        $this->storage->setExpiration($uuid, new \DateTimeImmutable('-1 minute'));

        $result = $this->transfer()->move([$uuid]);

        $this->assertSame([$uuid => 'expired'], $result->skipped);
        $this->assertFalse($this->target->exists($uuid));
        $this->assertTrue($this->storage->exists($uuid), 'a skipped object stays');
    }

    public function testDamagedObjectsAreNotCopied(): void
    {
        $uuid = $this->storage->store(new Product('Apple'));
        file_put_contents($this->storage->getFilePathData($uuid), '{"name":"Tampered"}');
        $missing = '6f9619ff-8b86-4011-b42d-00c04fc964ff';

        $result = $this->transfer()->move([$uuid, $missing]);

        $this->assertStringContainsString('Checksum mismatch', $result->failed[$uuid]);
        $this->assertSame('not found', $result->failed[$missing]);
        $this->assertFalse($this->target->exists($uuid));
        $this->assertTrue($this->storage->exists($uuid), 'not deleted when not copied');
    }

    public function testAnInterruptedWriteIsCompletedBeforeCopying(): void
    {
        $uuid = $this->storage->store(new Product('Before'));

        // a crash after the data was committed, before the metadata: the files don't match until recovered
        $crashingWriter = new class extends Writer {
            private int $commits = 0;

            protected function commit(string $filename): void
            {
                if (2 === ++$this->commits) {
                    throw new RuntimeException('simulated crash');
                }
                parent::commit($filename);
            }
        };
        $crashing = new ObjectStorage($this->storage->getStorageDir());
        $crashing->setWriter($crashingWriter);
        try {
            $crashing->store(new Product('After'), $uuid);
        } catch (Throwable) {
        }

        $source = new ObjectStorage($this->storage->getStorageDir());
        $result = (new ObjectTransfer($source, $this->target))->copy([$uuid]);

        $this->assertSame([$uuid], $result->transferred, implode(', ', $result->failed));
        $this->assertSame('After', $this->target->load($uuid)->name);
    }

    public function testWithoutChecksumValidationTheDataIsCopiedAsItIs(): void
    {
        $uuid = $this->storage->store(new Product('Apple'));
        file_put_contents($this->storage->getFilePathData($uuid), '{"name":"Edited by hand"}');

        $failed = $this->transfer()->copy([$uuid]);
        $this->assertStringContainsString('(crc32b, metadata version 2)', $failed->failed[$uuid], 'the cause is named');

        // as when loading: with validation disabled, the data is used as it is
        $this->storage->getStrategy()->disableChecksumValidation();
        $this->assertSame([$uuid], $this->transfer()->copy([$uuid])->transferred);
    }

    public function testMetadataOfOlderVersionsWithoutUuid(): void
    {
        $uuid = $this->storage->store(new Product('Apple'));

        // metadata as older versions wrote it: without the UUID, which only the file name gives
        $file = $this->storage->getFilePathMetadata($uuid);
        $metadata = json_decode(file_get_contents($file), true);
        unset($metadata['uuid']);
        file_put_contents($file, json_encode($metadata));
        $source = new ObjectStorage($this->storage->getStorageDir());

        $result = (new ObjectTransfer($source, $this->target))->move([$uuid]);

        $this->assertSame([$uuid], $result->transferred, implode(', ', $result->failed));
        $this->assertSame($uuid, $this->target->loadMetadata($uuid)->getUUID(), 'the copy names its UUID');
        $this->assertSame('Apple', $this->target->load($uuid)->name);
    }

    public function testMetadataWithoutUuidCanBeChanged(): void
    {
        $uuid = $this->storage->store(new Product('Apple'));
        $file = $this->storage->getFilePathMetadata($uuid);
        $metadata = json_decode(file_get_contents($file), true);
        unset($metadata['uuid']);
        file_put_contents($file, json_encode($metadata));
        $storage = new ObjectStorage($this->storage->getStorageDir());

        // writing metadata needs its UUID, e.g. to set the lifetime
        $storage->setLifetime($uuid, 3600);

        $this->assertSame($uuid, json_decode(file_get_contents($file), true)['uuid']);
        $this->assertGreaterThan(3500, $storage->getLifetime($uuid));
    }

    public function testDuplicatesWithTheMetadataOfTheOriginal(): void
    {
        // a duplicate as older versions of the viewer made it: data and metadata copied, naming the original
        $original = $this->storage->store(new Product('Apple'));
        $duplicate = '11d9d23c-b70a-4ddf-be43-25eac1d29fca';
        $this->putFile($this->storage->getFilePathData($duplicate), file_get_contents($this->storage->getFilePathData($original)));
        $this->putFile($this->storage->getFilePathMetadata($duplicate), file_get_contents($this->storage->getFilePathMetadata($original)));
        $this->storage->createStub(Product::class, $duplicate);
        $originalMetadata = file_get_contents($this->storage->getFilePathMetadata($original));
        $storage = new ObjectStorage($this->storage->getStorageDir());

        // changing the metadata of the duplicate doesn't touch the original anymore
        $storage->setLifetime($duplicate, 3600);
        $this->assertSame($originalMetadata, file_get_contents($this->storage->getFilePathMetadata($original)));
        $this->assertSame($duplicate, json_decode(file_get_contents($this->storage->getFilePathMetadata($duplicate)), true)['uuid']);

        $result = (new ObjectTransfer($storage, $this->target))->move([$duplicate]);
        $this->assertSame([$duplicate], $result->transferred, implode(', ', $result->failed));
        $this->assertSame($duplicate, $this->target->loadMetadata($duplicate)->getUUID());
        $this->assertSame('Apple', $this->target->load($duplicate)->name);
        $this->assertSame('Apple', $storage->load($original)->name, 'the original is intact');
    }

    public function testAttachedSearchIndexesOfTheTargetLearnTheCopies(): void
    {
        $search = new Manager($this->target, [new ArrayEngine()]);
        $index = $search->create(new Definition('products', Product::class, [new Field('name', 'name')]));
        $index->build();
        $search->attach();

        $uuid = $this->storage->store(new Product('Apricot'));
        $this->transfer()->copy([$uuid]);

        $this->assertSame([$uuid], $index->search(new Request('apricot'))->getUuids());
        $search->detach();
    }

    public function testProgressAndTheSameStorage(): void
    {
        $uuid = $this->storage->store(new Product('Apple'));
        $outcomes = [];
        $this->transfer()->copy([$uuid, $uuid], onProgress: function (string $uuid, string $outcome) use (&$outcomes) {
            $outcomes[] = $outcome;
        });
        $this->assertSame(['transferred'], $outcomes, 'each object once');

        $this->expectException(InvalidArgumentException::class);
        new ObjectTransfer($this->storage, new ObjectStorage($this->storage->getStorageDir()));
    }
}
