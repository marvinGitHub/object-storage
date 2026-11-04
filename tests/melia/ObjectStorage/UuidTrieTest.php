<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\UUID\Exception\InvalidUUIDException;
use melia\ObjectStorage\UUID\Generator\Generator;
use melia\ObjectStorage\Util\UuidTrie;

class UuidTrieTest extends TestCase
{
    /**
     * @return string[]
     */
    private function uuids(int $count): array
    {
        $generator = new Generator();
        $uuids = [];
        for ($i = 0; $i < $count; $i++) {
            $uuids[] = $generator->generate();
        }
        return $uuids;
    }

    public function testEmptyTrie(): void
    {
        $trie = new UuidTrie();

        $this->assertSame(0, $trie->count());
        $this->assertTrue($trie->isEmpty());
        $this->assertSame([], iterator_to_array($trie));
        $this->assertFalse($trie->contains('7d3f4b5e-2a1c-4e8f-9b6d-0c1a2b3c4d5e'));
        $this->assertSame(0, $trie->remove('7d3f4b5e-2a1c-4e8f-9b6d-0c1a2b3c4d5e'));
    }

    public function testAddContainsRemove(): void
    {
        [$a, $b, $c] = $this->uuids(3);
        $trie = new UuidTrie($a, $b);

        $this->assertSame(1, $trie->add($a, $c), 'contained UUIDs are ignored');
        $this->assertSame(0, $trie->add(strtoupper($a)), 'UUIDs are case-insensitive');

        $this->assertTrue($trie->contains($a));
        $this->assertTrue($trie->contains(strtoupper($b)));
        $this->assertSame(3, $trie->count());

        $this->assertSame(1, $trie->remove($b, '00000000-0000-4000-8000-000000000000'));
        $this->assertFalse($trie->contains($b));
        $this->assertSame(2, $trie->count());
    }

    public function testSharedPrefixesAreSplitAndMergedAgain(): void
    {
        $a = '7d3f4b5e-2a1c-4e8f-9b6d-0c1a2b3c4d5e';
        $b = '7d3f4b5e-2a1c-4e8f-9b6d-0c1a2b3c4d5f'; // differs in the last digit
        $c = '7d3f0000-0000-4000-8000-000000000000';

        $trie = new UuidTrie($a);
        $this->assertSame(['root' => ['k7d3f4b5e2a1c4e8f9b6d0c1a2b3c4d5e' => true]], array_intersect_key($trie->__serialize(), ['root' => 1]));

        $trie->add($b, $c);
        // the order of the edges in a node doesn't matter, iteration sorts them
        $this->assertEquals([
            'k7d3f' => [
                'k4b5e2a1c4e8f9b6d0c1a2b3c4d5' => ['ke' => true, 'kf' => true],
                'k0000000040008000000000000000' => true,
            ],
        ], $trie->__serialize()['root']);

        $this->assertFalse($trie->contains('7d3f4b5e-2a1c-4e8f-9b6d-0c1a2b3c4d50'), 'a prefix of contained UUIDs isn\'t contained');

        // removing merges nodes left with one child into their edge
        $trie->remove($b, $c);
        $this->assertSame(['k7d3f4b5e2a1c4e8f9b6d0c1a2b3c4d5e' => true], $trie->__serialize()['root']);
        $this->assertTrue($trie->contains($a));
    }

    public function testIteratesInOrder(): void
    {
        $uuids = $this->uuids(500);
        $trie = new UuidTrie();
        foreach (array_chunk($uuids, 37) as $chunk) {
            $trie->add(...$chunk);
        }

        sort($uuids, SORT_STRING);
        $this->assertSame($uuids, iterator_to_array($trie, false));
        $this->assertSame(500, $trie->count());

        foreach ($uuids as $uuid) {
            $this->assertTrue($trie->contains($uuid));
        }
    }

    public function testIterationContinuesAfterAUuid(): void
    {
        $uuids = $this->uuids(100);
        $trie = new UuidTrie(...$uuids);
        sort($uuids, SORT_STRING);

        // in steps, as a build does
        $collected = [];
        $cursor = null;
        while ([] !== ($batch = $trie->first(7, $cursor))) {
            array_push($collected, ...$batch);
            $cursor = end($batch);
        }
        $this->assertSame($uuids, $collected);

        // after a UUID that isn't contained
        $this->assertSame($uuids, iterator_to_array($trie->iterate('00000000-0000-4000-8000-000000000000'), false));
        $this->assertSame([], iterator_to_array($trie->iterate('ffffffff-ffff-4fff-bfff-ffffffffffff'), false));
        $this->assertSame(array_slice($uuids, 51), iterator_to_array($trie->iterate($uuids[50]), false));
    }

    public function testRandomOperationsMatchAPlainSet(): void
    {
        mt_srand(7);
        $pool = $this->uuids(300);
        $trie = new UuidTrie();
        $set = [];

        for ($i = 0; $i < 3000; $i++) {
            $uuid = $pool[mt_rand(0, count($pool) - 1)];
            if (mt_rand(0, 2) > 0) {
                $this->assertSame(isset($set[$uuid]) ? 0 : 1, $trie->add($uuid));
                $set[$uuid] = true;
            } else {
                $this->assertSame(isset($set[$uuid]) ? 1 : 0, $trie->remove($uuid));
                unset($set[$uuid]);
            }
        }

        $expected = array_keys($set);
        sort($expected, SORT_STRING);
        $this->assertSame($expected, iterator_to_array($trie, false));
        $this->assertSame(count($expected), $trie->count());
    }

    public function testClear(): void
    {
        $trie = new UuidTrie(...$this->uuids(50));

        $trie->clear();

        $this->assertTrue($trie->isEmpty());
        $this->assertSame([], iterator_to_array($trie));
    }

    public function testIsStoredAsOneRecord(): void
    {
        $uuids = $this->uuids(2000);
        $uuid = $this->storage->store(new UuidTrie(...$uuids));

        $this->assertSame([UuidTrie::class], array_values($this->storage->getClassNames()));
        $this->assertCount(1, iterator_to_array($this->storage->list(), false), 'one record, however many UUIDs');

        // readable as a trie in the stored data
        $data = json_decode(file_get_contents($this->storage->getFilePathData($uuid)), true);
        $this->assertSame(2000, $data['count']);
        $this->assertIsArray($data['root']);

        $this->storage->clearCache();
        $loaded = $this->storage->load($uuid);
        $this->assertInstanceOf(UuidTrie::class, $loaded);

        sort($uuids, SORT_STRING);
        $this->assertSame($uuids, iterator_to_array($loaded, false));
        $this->assertTrue($loaded->contains($uuids[1234]));

        // changed and stored again
        $loaded->remove(...array_slice($uuids, 0, 1000));
        $this->storage->store($loaded, $uuid);
        $this->storage->clearCache();
        $this->assertSame(array_slice($uuids, 1000), iterator_to_array($this->storage->load($uuid), false));
    }

    public function testInvalidUuidIsRejected(): void
    {
        $this->expectException(InvalidUUIDException::class);
        (new UuidTrie())->add('not-a-uuid');
    }
}
