<?php

namespace Tests\melia\ObjectStorage;

use stdClass;


class ObjectStorageSearchTest extends TestCase
{
    public function testSearchSimpleEquality(): void
    {
        $a = new stdClass();
        $a->type = 'simple';
        $a->name = 'alpha';

        $b = new stdClass();
        $b->type = 'simple';
        $b->name = 'beta';

        $this->storage->store($a);
        $this->storage->store($b);

        $result = [];
        foreach ($this->storage->match(function (stdClass $object) {
            return isset($object->name) && $object->name === 'alpha';
        }, stdClass::class) as $uuid => $object) {
            $result[$uuid] = $object;
        }
        $this->assertCount(1, $result);
        $only = array_values($result)[0];
        $this->assertEquals('alpha', $only->name);
    }

    public function testSearchNestedObjectProperty(): void
    {
        $parent1 = new stdClass();
        $child1 = new stdClass();
        $child1->name = 'Alice';
        $child1->age = 30;
        $parent1->child = $child1;

        $parent2 = new stdClass();
        $child2 = new stdClass();
        $child2->name = 'Bob';
        $child2->age = 35;
        $parent2->child = $child2;

        $this->storage->store($parent1);
        $this->storage->store($parent2);

        $result = [];
        foreach ($this->storage->match(function (stdClass $object) {
            return isset($object->child->name) && $object->child->name === 'Alice';
        }, stdClass::class) as $uuid => $object) {
            $result[$uuid] = $object;
        }

        $this->assertCount(1, $result);
        $only = array_values($result)[0];
        $this->assertEquals('Alice', $only->child->name);
    }

    public function testSearchArrayIndex(): void
    {
        $o1 = new stdClass();
        $o1->items = [];
        $itemA = new stdClass();
        $itemA->sku = 'A1';
        $itemA->price = 99.99;
        $o1->items[] = $itemA;

        $o2 = new stdClass();
        $o2->items = [];
        $itemB = new stdClass();
        $itemB->sku = 'B2';
        $itemB->price = 10.00;
        $o2->items[] = $itemB;

        $this->storage->store($o1);
        $this->storage->store($o2);

        // Bracket-Notation (Index)
        $result = [];
        foreach ($this->storage->match(function (stdClass $object) {
            return isset($object->items[0]->sku) && $object->items[0]->sku === 'A1';
        }, stdClass::class) as $uuid => $object) {
            $result[$uuid] = $object;
        }
        $this->assertCount(1, $result);
        $only = array_values($result)[0];
        $this->assertEquals('A1', $only->items[0]->sku);
    }

    public function testSearchWildcardAnyElementMatches(): void
    {
        $o1 = new stdClass();
        $o1->items = [];
        $i1 = new stdClass();
        $i1->price = 25.00;
        $i2 = new stdClass();
        $i2->price = 75.00;
        $o1->items = [$i1, $i2];

        $o2 = new stdClass();
        $o2->items = [];
        $j1 = new stdClass();
        $j1->price = 20.00;
        $j2 = new stdClass();
        $j2->price = 30.00;
        $o2->items = [$j1, $j2];

        $this->storage->store($o1);
        $this->storage->store($o2);

        // Wildcard über Array-Elemente: irgendein Preis > 50
        $result = [];
        foreach ($this->storage->match(function (stdClass $object) {
            return isset($object->items[1]->price) && $object->items[1]->price > 50;
        }, stdClass::class) as $uuid => $object) {
            $result[$uuid] = $object;
        }

        $this->assertCount(1, $result);
        $only = array_values($result)[0];
        $this->assertEquals(75.00, $only->items[1]->price);
    }

    public function testSearchNestedMultipleLevels(): void
    {
        $o1 = new stdClass();
        $c1 = new stdClass();
        $c1->name = 'Container';
        $c1->meta = new stdClass();
        $c1->meta->flags = ['hot', 'featured'];
        $o1->container = $c1;

        $o2 = new stdClass();
        $c2 = new stdClass();
        $c2->name = 'Container';
        $c2->meta = new stdClass();
        $c2->meta->flags = ['cold'];
        $o2->container = $c2;

        $this->storage->store($o1);
        $this->storage->store($o2);

        // Wildcard über flags
        $result = [];
        foreach ($this->storage->match(function (stdClass $object) {
            return isset($object->container->meta->flags) && in_array('featured', $object->container->meta->flags);
        }) as $uuid => $object) {
            $result[$uuid] = $object;
        }

        $this->assertCount(1, $result);
        $only = array_values($result)[0];
        $this->assertContains('featured', $only->container->meta->flags);
    }

    public function testSearchLazyLoadChildUnwraps(): void
    {
        // parent->child ist ein Objekt und wird als Referenz gespeichert, beim Laden LazyLoadReference
        $parentA = new stdClass();
        $childA = new stdClass();
        $childA->name = 'Lazy-A';
        $parentA->child = $childA;

        $parentB = new stdClass();
        $childB = new stdClass();
        $childB->name = 'Lazy-B';
        $parentB->child = $childB;

        $this->storage->store($parentA);
        $this->storage->store($parentB);

        // Cache leeren, um LazyLoadReferences beim Laden zu erhalten
        $this->storage->clearCache();

        $result = [];
        foreach ($this->storage->match(function (stdClass $object) {
            return isset($object->child->name) && $object->child->name === 'Lazy-B';
        }) as $uuid => $object) {
            $result[$uuid] = $object;
        }

        $this->assertCount(1, $result);
        $only = array_values($result)[0];
        // Zugriff sollte referenziertes Objekt liefern
        $this->assertEquals('Lazy-B', $only->child->name);
    }

    public function testSearchWithLikeOperatorOnNested(): void
    {
        $o1 = new stdClass();
        $o1->profile = new stdClass();
        $o1->profile->email = 'john.doe@example.com';

        $o2 = new stdClass();
        $o2->profile = new stdClass();
        $o2->profile->email = 'alice@test.local';

        $this->storage->store($o1);
        $this->storage->store($o2);

        $result = [];
        foreach ($this->storage->match(function (stdClass $object) {
            return isset($object->profile->email) && strpos($object->profile->email, '@example.com') !== false;
        }) as $uuid => $object) {
            $result[$uuid] = $object;
        }

        $this->assertCount(1, $result);
        $only = array_values($result)[0];
        $this->assertEquals('john.doe@example.com', $only->profile->email);
    }

    public function testSearchMultipleCriteriaAllMustMatch(): void
    {
        $o1 = new stdClass();
        $o1->type = 'order';
        $o1->customer = new stdClass();
        $o1->customer->name = 'Jane';
        $o1->lines = [];
        $l1 = new stdClass();
        $l1->sku = 'A';
        $l1->qty = 2;
        $l2 = new stdClass();
        $l2->sku = 'B';
        $l2->qty = 1;
        $o1->lines = [$l1, $l2];

        $o2 = new stdClass();
        $o2->type = 'order';
        $o2->customer = new stdClass();
        $o2->customer->name = 'John';
        $o2->lines = [];
        $m1 = new stdClass();
        $m1->sku = 'A';
        $m1->qty = 5;
        $o2->lines = [$m1];

        $this->storage->store($o1);
        $this->storage->store($o2);

        $result = [];
        foreach ($this->storage->match(function (stdClass $object) {
            return isset($object->customer->name) && $object->customer->name === 'Jane' &&
                isset($object->lines) && is_array($object->lines) && in_array('B', array_map(function ($line) {
                    return $line->sku;
                }, $object->lines)) &&
                isset($object->type) && $object->type === 'order';
        }) as $uuid => $object) {
            $result[$uuid] = $object;
        }

        $this->assertCount(1, $result);
        $only = array_values($result)[0];
        $this->assertEquals('Jane', $only->customer->name);
    }

    public function testSearchBracketAndDotNotationMixed(): void
    {
        $o = new stdClass();
        $o->meta = new stdClass();
        $o->meta->tags = [
            ['name' => 'alpha'],
            ['name' => 'beta'],
        ];

        $this->storage->store($o);

        // gemischte Schreibweise: meta.tags[1].name
        $result = [];
        foreach ($this->storage->match(function (stdClass $object) {
            return isset($object->meta->tags) && is_array($object->meta->tags) && in_array('beta', array_map(function ($tag) {
                    return $tag['name'];
                }, $object->meta->tags));
        }) as $uuid => $object) {
            $result[$uuid] = $object;
        }

        $this->assertCount(1, $result);
    }

    public function testSearchRegexOperatorOnNested(): void
    {
        $o1 = new stdClass();
        $o1->user = new stdClass();
        $o1->user->username = 'dev_jack';

        $o2 = new stdClass();
        $o2->user = new stdClass();
        $o2->user->username = 'ops_jill';

        $this->storage->store($o1);
        $this->storage->store($o2);

        $result = [];
        foreach ($this->storage->match(function (stdClass $object) {
            return isset($object->user->username) && preg_match('/^dev_/', $object->user->username);
        }) as $uuid => $object) {
            $result[$uuid] = $object;
        }

        $this->assertCount(1, $result);
        $only = array_values($result)[0];
        $this->assertEquals('dev_jack', $only->user->username);
    }

    public function testSearchWithSubSet()
    {
        $o1 = new stdClass();
        $o1->user = new stdClass();
        $o1->user->username = 'dev_jack';
        $o1->user->roles = ['admin', 'user'];

        $o2 = new stdClass();
        $o2->user = new stdClass();
        $o2->user->username = 'ops_jill';
        $o2->user->roles = ['admin'];

        $this->storage->store($o1);
        $this->storage->store($o2);

        $result = [];
        foreach ($this->storage->match(function (stdClass $object) {
            return true;
        }) as $uuid => $object) {
            $result[$uuid] = $object;
        }

        $this->assertCount(4, $result);

        $result2 = [];
        foreach ($this->storage->match(matcher: function (stdClass $object) {
            return isset($object->roles) && is_array($object->roles) && in_array('admin', $object->roles);
        }, subSet: $result) as $uuid => $object) {
            $result2[$uuid] = $object;
        }

        $this->assertCount(2, $result2);

        $result3 = [];
        foreach ($this->storage->match(matcher: function (stdClass $object) {
            return isset($object->username) && 'dev_jack' === $object->username;
        }, subSet: array_keys($result2)) as $uuid => $object) {
            $result3[$uuid] = $object;
        }

        $this->assertCount(1, $result3);
    }
}