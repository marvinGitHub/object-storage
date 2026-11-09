<?php

namespace Tests\melia\ObjectStorage\Query;

use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Query\Oql;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\Manager as SearchManager;
use melia\ObjectStorage\Search\Request;
use melia\ObjectStorage\Transfer\RawObject;
use stdClass;
use Tests\melia\ObjectStorage\Search\ArrayEngine;
use Tests\melia\ObjectStorage\Search\Category;
use Tests\melia\ObjectStorage\Search\Product;
use Tests\melia\ObjectStorage\TestCase;

/**
 * ALTER CLASS … DROP ATTRIBUTE (and ALTER TABLE … DROP COLUMN): properties removed from the stored objects.
 */
class OqlDropAttributeTest extends TestCase
{
    private Oql $oql;
    /** @var array<string, string> */
    private array $uuids = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Homa' => 'Berlin', 'Alex' => 'Köln', 'Kim' => null] as $name => $city) {
            $user = new stdClass();
            $user->name = $name;
            $user->city = $city;
            $user->legacy = 'old ' . $name;
            if ('Kim' === $name) {
                unset($user->legacy);
            }
            $this->uuids[$name] = $this->storage->store($user);
        }
        $this->oql = new Oql($this->storage);
    }

    private function dataOf(string $uuid): array
    {
        $this->storage->removeFromCache($uuid);
        return json_decode($this->storage->readRaw($uuid)->data, true);
    }

    public function testDropAttribute(): void
    {
        $revision = $this->storage->readRaw($this->uuids['Homa'])->metadata->getRevision();
        $result = $this->oql->query('ALTER CLASS stdClass DROP ATTRIBUTE legacy');
        $this->assertSame('ALTER', $result->statement);
        $this->assertSame(2, $result->affected(), 'only the objects that had it');
        $this->assertEqualsCanonicalizing([$this->uuids['Homa'], $this->uuids['Alex']], $result->column(0));

        $this->assertSame(['city' => 'Berlin', 'name' => 'Homa'], $this->dataOf($this->uuids['Homa']));
        $raw = $this->storage->readRaw($this->uuids['Homa']);
        $this->assertSame($revision + 1, $raw->metadata->getRevision());
        $this->assertSame(hash($raw->metadata->getChecksumAlgorithm(), $raw->data), $raw->metadata->getChecksum());
        $this->assertSame('Homa', $this->storage->load($this->uuids['Homa'])->name, 'loads as before');
        $this->assertFalse(property_exists($this->storage->load($this->uuids['Alex']), 'legacy'));

        $this->assertSame(0, $this->oql->query('ALTER CLASS stdClass DROP ATTRIBUTE legacy')->affected(), 'nothing left to drop');
    }

    public function testDropColumnForSql(): void
    {
        foreach (['ALTER TABLE stdClass DROP COLUMN legacy', 'alter table `stdClass` drop column legacy;', 'ALTER TABLE stdClass DROP legacy'] as $query) {
            $this->assertTrue(Oql::writes($query), $query);
            $this->assertSame(2, $this->oql->query($query, dryRun: true)->affected(), $query);
        }
        $this->assertSame(3, $this->oql->query('ALTER TABLE stdClass DROP COLUMN legacy, DROP COLUMN city')->affected());
        $this->assertSame(['name' => 'Kim'], $this->dataOf($this->uuids['Kim']));
    }

    public function testDryRunWritesNothing(): void
    {
        $result = $this->oql->query('ALTER CLASS stdClass DROP ATTRIBUTE legacy', dryRun: true);
        $this->assertTrue($result->dryRun);
        $this->assertSame(2, $result->affected());
        $this->assertSame('old Homa', $this->dataOf($this->uuids['Homa'])['legacy']);
    }

    public function testWordsArentReserved(): void
    {
        $object = new stdClass();
        $object->column = 1;
        $object->attribute = 2;
        $object->class = 3;
        $uuid = $this->storage->store($object);
        $this->assertSame([[1, 2, 3]], $this->oql->query('SELECT column, attribute, class FROM stdClass WHERE UUID = ?', [$uuid])->rows);
        $this->oql->query('ALTER CLASS stdClass DROP ATTRIBUTE column, DROP attribute');
        $this->assertSame(['class' => 3], $this->dataOf($uuid));
    }

    public function testDeclaredPropertiesArentDropped(): void
    {
        $this->storage->store(new Product('Apple', 'red', 1.5, ['red'], new Category('Fruit')));
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/declares the property price/');
        $this->oql->query('ALTER CLASS Product DROP ATTRIBUTE price');
    }

    public function testPropertiesRemovedFromTheClass(): void
    {
        // stored by an older version of the class that had the property "discount"
        $uuid = $this->storage->store(new Product('Apple', 'red', 1.5, ['red'], new Category('Fruit')));
        $raw = $this->storage->readRaw($uuid);
        $data = json_decode($raw->data, true) + ['discount' => 10];
        ksort($data);
        $json = json_encode($data);
        $metadata = clone $raw->metadata;
        $metadata->setChecksum(hash($metadata->getChecksumAlgorithm(), $json));
        $this->storage->writeRaw(new RawObject($uuid, $json, $metadata), true);

        $this->assertSame(1, $this->oql->query('ALTER CLASS Product DROP ATTRIBUTE discount')->affected());
        $data = $this->dataOf($uuid);
        $this->assertArrayNotHasKey('discount', $data);
        $this->assertSame(['__reference'], array_keys($data['category']), 'references kept');
        $this->assertSame('Fruit', $this->storage->load($uuid)->category->title);
    }

    public function testSearchIndexesFollow(): void
    {
        $search = new SearchManager($this->storage, [new ArrayEngine()]);
        $index = $search->create(new Definition('users', stdClass::class, [new Field('legacy', 'legacy', filterable: true)]));
        $index->build();
        $search->attach();
        try {
            $this->oql->query('ALTER CLASS stdClass DROP ATTRIBUTE legacy');
            $search->sync();
            $hit = $index->search(new Request(filter: "uuid = '{$this->uuids['Homa']}'"))->hits[0];
            $this->assertNull($hit->document['legacy']);
        } finally {
            $search->detach();
        }
    }

    public function testErrors(): void
    {
        foreach ([
                     'ALTER stdClass DROP ATTRIBUTE legacy' => 'ALTER CLASS',
                     'ALTER CLASS stdClass ADD ATTRIBUTE x' => 'DROP ATTRIBUTE',
                     'ALTER CLASS stdClass DROP ATTRIBUTE a.b' => 'not paths',
                     'ALTER CLASS stdClass DROP ATTRIBUTE a, DROP COLUMN a' => 'twice',
                     'ALTER CLASS Nope DROP ATTRIBUTE a' => 'Unknown class',
                 ] as $query => $message) {
            try {
                $this->oql->query($query);
                $this->fail($query);
            } catch (QueryException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $query);
            }
        }
    }
}
