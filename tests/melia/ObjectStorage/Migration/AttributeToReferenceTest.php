<?php

namespace Tests\melia\ObjectStorage\Migration;

use melia\ObjectStorage\Migration\AttributeToReference;
use melia\ObjectStorage\Migration\Exception\Exception;
use Tests\melia\ObjectStorage\Search\Category;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\Manager;
use melia\ObjectStorage\Search\Request;
use stdClass;
use Tests\melia\ObjectStorage\Search\ArrayEngine;
use Tests\melia\ObjectStorage\TestCase;

class AttributeToReferenceTest extends TestCase
{
    private function person(string $name, mixed $friend = null): stdClass
    {
        $person = new stdClass();
        $person->name = $name;
        if (null !== $friend) {
            $person->friend = $friend;
        }
        return $person;
    }

    private function rawData(string $uuid): array
    {
        return json_decode(file_get_contents($this->storage->getFilePathData($uuid)), true);
    }

    private function link(string $uuid, string $attribute): AttributeToReference
    {
        $migration = new AttributeToReference($attribute);
        $migration->setStorage($this->storage);
        // loaded from the files, like in another request
        $this->storage->clearCache();
        $migration->apply($this->storage->load($uuid), $uuid);
        return $migration;
    }

    public function testLoadedObjectsAreUpdatedNotCopied(): void
    {
        $bob = $this->storage->store($this->person('Bob'));
        $anna = $this->storage->store($this->person('Anna', $bob));

        $this->link($anna, 'friend');

        $this->assertSame(['__reference' => $bob], $this->rawData($anna)['friend']);
        $this->assertCount(2, iterator_to_array($this->storage->list(stdClass::class)), 'no copies of Anna or Bob');
    }

    public function testATypedAttributeCantHoldAReference(): void
    {
        $bob = $this->storage->store($this->person('Bob'));
        $category = $this->storage->store(new Category($bob));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('typed');
        $this->link($category, 'title');
    }

    public function testAUuidBecomesAReference(): void
    {
        $bob = $this->storage->store($this->person('Bob'));
        $anna = $this->storage->store($this->person('Anna', $bob));
        $this->assertSame($bob, $this->rawData($anna)['friend'], 'text before');

        $migration = new AttributeToReference('friend');
        $migration->setStorage($this->storage);
        $this->assertTrue($migration->apply($this->storage->load($anna)));

        $this->assertSame(['__reference' => $bob], $this->rawData($anna)['friend']);
        $this->storage->clearCache();
        $this->assertSame('Bob', $this->storage->load($anna)->friend->name);

        $this->assertFalse($migration->apply($this->storage->load($anna)), 'already a reference');
    }

    public function testListsAndValuesThatArentUuidsOfObjects(): void
    {
        $bob = $this->storage->store($this->person('Bob'));
        $carl = $this->storage->store($this->person('Carl'));
        $unknown = '00000000-0000-4000-8000-000000000001';
        $anna = $this->storage->store($this->person('Anna', [$bob, 'not a uuid', $unknown, $carl]));

        $migration = $this->link($anna, 'friend');

        $this->assertSame([['__reference' => $bob], 'not a uuid', $unknown, ['__reference' => $carl]], $this->rawData($anna)['friend']);
        $this->assertSame([$unknown], $migration->getMissing());

        $plain = $this->storage->store($this->person('Plain'));
        $this->assertSame([], $this->link($plain, 'friend')->getMissing(), 'without the attribute');
        $this->assertArrayNotHasKey('friend', $this->rawData($plain));
    }

    public function testSearchIndexesFollowTheNewReference(): void
    {
        $search = new Manager($this->storage, [new ArrayEngine()]);
        try {
            $bob = $this->storage->store($this->person('Bob'));
            $anna = $this->storage->store($this->person('Anna', $bob));
            $index = $search->create(new Definition('people', stdClass::class, [
                new Field('name', 'name'),
                new Field('friendName', 'friend.name', filterable: true),
            ]));
            $index->build();
            $search->attach();

            $find = static fn(): array => $index->search(new Request(filter: "friendName = 'Bob'"))->getUuids();
            $this->assertSame([], $find(), 'text: not followed');

            $this->link($anna, 'friend');
            $this->assertSame([$anna], $find());
        } finally {
            $search->detach();
        }
    }
}
