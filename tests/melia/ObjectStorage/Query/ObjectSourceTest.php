<?php

namespace Tests\melia\ObjectStorage\Query;

use melia\ObjectStorage\Query\Ast\Source;
use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Query\ObjectSource;
use RuntimeException;
use Tests\melia\ObjectStorage\TestCase;

class ObjectSourceTest extends TestCase
{
    public function testTheObjectsOfASourceAreReadAsStored(): void
    {
        $alex = $this->storage->store(new Person('Alex', 30));
        $this->storage->store(new Person('Homa', 40, friend: new Person('Kim')));
        $this->storage->store(new Company('Acme'));
        $source = new ObjectSource($this->storage);

        $objects = iterator_to_array($source->objectsOf(new Source('Person', 'p', 0), null), false);

        $this->assertCount(3, $objects);
        $this->assertEqualsCanonicalizing(['Alex', 'Homa', 'Kim'], array_map(static fn(array $o): string => $o['data']['name'], $objects), 'referenced objects of the class too, not other classes');
        $this->assertSame(Person::class, $objects[0]['class']);
        $this->assertSame(3, $source->objectsRead());
        $this->assertSame([['source' => 'p', 'class' => Person::class, 'access' => 'all objects of the class']], $source->accessed());
        $this->assertContains($alex, array_column($objects, 'uuid'));
    }

    public function testClassesByTheirShortName(): void
    {
        $this->storage->store(new Person('Alex'));
        $source = new ObjectSource($this->storage);

        $this->assertSame(Person::class, $source->classOf(new Source('person', 'p', 0)));
        $this->assertSame(Person::class, $source->classOf(new Source(Person::class, 'p', 0)));
        $this->assertSame(Company::class, $source->classOf(new Source(Company::class, 'c', 0)), 'a class without stored objects');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Unknown class "Nobody"');
        $source->classOf(new Source('Nobody', 'n', 0));
    }

    public function testReferencedObjectsAreKeptUntilForgotten(): void
    {
        $uuid = $this->storage->store(new Person('Kim'));
        $source = new ObjectSource($this->storage);
        $follow = $source->follow();

        $this->assertSame('Kim', $follow($uuid)[0]['name']);
        $follow($uuid);
        $this->assertSame(1, $source->objectsRead(), 'read once');
        $this->assertNull($follow('00000000-0000-4000-8000-000000000000'), 'missing');

        $source->forgetReferenced();
        $follow($uuid);
        $this->assertSame(3, $source->objectsRead(), 'read again');
    }

    public function testTheInterruptIsAskedForEveryObject(): void
    {
        $this->storage->store(new Person('Alex'));
        $this->storage->store(new Person('Homa'));
        $asked = 0;
        $source = new ObjectSource($this->storage, interrupt: static function () use (&$asked): void {
            if (++$asked > 1) {
                throw new RuntimeException('stop');
            }
        });

        $this->expectExceptionMessage('stop');
        try {
            iterator_to_array($source->objectsOf(new Source('Person', 'p', 0), null));
        } finally {
            $this->assertSame(2, $asked);
            $this->assertSame(1, $source->objectsRead(), 'stopped before the second');
        }
    }
}
