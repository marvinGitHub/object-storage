<?php

namespace Tests\melia\ObjectStorage\Query;

use DateTimeInterface;
use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Query\Oql;
use stdClass;
use Tests\melia\ObjectStorage\Search\Category;
use Tests\melia\ObjectStorage\Search\Product;
use Tests\melia\ObjectStorage\TestCase;

/**
 * UPDATE with paths: SET newlist['today'] = NOW(), tags[0], tags[-1], tags[], settings.mail.enabled, friends.*.active,
 * and REMOVE of keys and elements; written in the object itself, never in referenced objects.
 */
class OqlUpdatePathTest extends TestCase
{
    private Oql $oql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oql = new Oql($this->storage);
    }

    private function object(array $properties): string
    {
        $object = new stdClass();
        foreach ($properties as $name => $value) {
            $object->{$name} = $value;
        }
        return $this->storage->store($object);
    }

    /** the data as stored */
    private function dataOf(string $uuid): array
    {
        $this->storage->removeFromCache($uuid);
        return json_decode($this->storage->readRaw($uuid)->data, true);
    }

    /** the object as loaded from the files, as another process would */
    private function load(string $uuid): object
    {
        $this->storage->clearCache();
        return $this->storage->load($uuid);
    }

    private function assertError(string $query, string $message, array $parameters = []): void
    {
        try {
            $this->oql->query($query, $parameters);
            $this->fail("No error: $query");
        } catch (QueryException $e) {
            $this->assertStringContainsString($message, $e->getMessage(), $query);
        }
    }

    public function testSetAKeyOfAMapThatIsntThereYet(): void
    {
        $uuid = $this->object(['name' => 'a']);
        $result = $this->oql->query("UPDATE stdClass SET newlist['today'] = NOW()");
        $this->assertSame([$uuid], $result->column(0));

        $loaded = $this->load($uuid);
        $this->assertIsArray($loaded->newlist);
        $this->assertSame(['today'], array_keys($loaded->newlist));
        $this->assertInstanceOf(DateTimeInterface::class, $loaded->newlist['today']);
        $this->assertSame([[true]], $this->oql->query("SELECT newlist['today'] <= NOW() FROM stdClass")->rows);

        // another key, the first stays
        $this->oql->query("UPDATE stdClass SET newlist['tomorrow'] = DATE_ADD(newlist['today'], 1, 'DAY')");
        $this->assertSame(['today', 'tomorrow'], array_keys($this->load($uuid)->newlist));
    }

    public function testKeysPositionsAndNames(): void
    {
        $uuid = $this->object(['tags' => ['a', 'b'], 'settings' => ['mail' => ['enabled' => false]]]);
        $this->oql->query("UPDATE stdClass s SET s.tags[0] = 'A', settings.mail.enabled = TRUE, settings['push'].enabled = FALSE");
        $this->oql->query("UPDATE stdClass SET tags[-1] = UPPER(tags[-1])");
        $data = $this->dataOf($uuid);
        $this->assertSame(['A', 'B'], $data['tags']);
        $this->assertSame(['mail' => ['enabled' => true], 'push' => ['enabled' => false]], $data['settings']);
    }

    public function testPositionsBeyondTheEndAndAppendingAsInPhp(): void
    {
        $uuid = $this->object(['tags' => ['a', 'b']]);
        $this->oql->query("UPDATE stdClass SET tags[] = 'c', tags[] = 'd'");
        $this->assertSame(['a', 'b', 'c', 'd'], $this->dataOf($uuid)['tags']);

        $this->oql->query("UPDATE stdClass SET tags[6] = 'g'");
        $this->assertSame([0 => 'a', 1 => 'b', 2 => 'c', 3 => 'd', 6 => 'g'], $this->dataOf($uuid)['tags']);
        $this->assertSame([0 => 'a', 1 => 'b', 2 => 'c', 3 => 'd', 6 => 'g'], $this->load($uuid)->tags);

        // a new element with a path in it
        $other = $this->object(['lines' => []]);
        $this->oql->query("UPDATE stdClass SET lines[].title = 'first' WHERE UUID = ?", [$other]);
        $this->assertSame([['title' => 'first']], $this->dataOf($other)['lines']);
    }

    public function testAllElements(): void
    {
        $two = $this->object(['friends' => [['name' => 'x'], ['name' => 'y', 'address' => ['city' => 'Berlin']]]]);
        $none = $this->object(['friends' => []]);
        $missing = $this->object(['name' => 'z']);

        $result = $this->oql->query("UPDATE stdClass SET friends.*.active = TRUE, friends[*].address.zip = '10115'");
        $this->assertSame([$two], $result->column(0), 'no elements: nothing changes');
        $this->assertSame([
            ['name' => 'x', 'active' => true, 'address' => ['zip' => '10115']],
            ['name' => 'y', 'address' => ['city' => 'Berlin', 'zip' => '10115'], 'active' => true],
        ], $this->dataOf($two)['friends']);
        $this->assertSame([], $this->dataOf($none)['friends']);
        $this->assertArrayNotHasKey('friends', $this->dataOf($missing));
    }

    public function testRemove(): void
    {
        $uuid = $this->object(['tags' => ['a', 'b', 'c', 'd'], 'settings' => ['mail' => 1, 'push' => 2, 'sms' => 3], 'friends' => [['name' => 'x', 'age' => 3], ['name' => 'y']]]);

        // positions as before removing; lists move up, maps keep their keys
        $this->oql->query("UPDATE stdClass REMOVE tags[0], tags[-1], settings['push'], friends.*.name");
        $data = $this->dataOf($uuid);
        $this->assertSame(['b', 'c'], $data['tags']);
        $this->assertSame(['mail' => 1, 'sms' => 3], $data['settings']);
        $this->assertSame([['age' => 3], []], $data['friends']);

        $this->assertSame(0, $this->oql->query("UPDATE stdClass REMOVE tags[5], settings['nope'], nothing['x'], tags[0].x")->affected(), 'nothing there: nothing written');

        // SET and REMOVE together
        $this->oql->query("UPDATE stdClass SET name = 'n', settings['push'] = 4 REMOVE tags[0], friends[1] WHERE name IS NULL");
        $data = $this->dataOf($uuid);
        $this->assertSame('n', $data['name']);
        $this->assertSame(['c'], $data['tags']);
        $this->assertSame(['mail' => 1, 'sms' => 3, 'push' => 4], $data['settings']);
        $this->assertSame([['age' => 3]], $data['friends']);
    }

    public function testRemoveIsNoKeyword(): void
    {
        $uuid = $this->object(['tags' => ['a', 'b'], 'remove' => 1]);
        $this->oql->query("UPDATE stdClass remove SET remove.tags[0] = 'x'");
        $this->oql->query("UPDATE stdClass REMOVE tags[1]");
        $this->oql->query("UPDATE stdClass AS remove REMOVE remove.tags[0] WHERE remove.remove = 1");
        $this->assertSame([], $this->dataOf($uuid)['tags']);
        $this->assertSame([[1]], $this->oql->query('SELECT remove FROM stdClass')->rows);
    }

    public function testReferencesInLists(): void
    {
        $target = $this->object(['name' => 'target']);
        $uuid = $this->object(['name' => 'holder', 'links' => []]);
        $this->oql->query("UPDATE stdClass SET links['main'] = REF(:t), links['all'][] = REF(:t) WHERE name = 'holder'", ['t' => $target]);
        $this->assertSame([['target', 'target']], $this->oql->query("SELECT links['main'].name, links['all'][0].name FROM stdClass WHERE name = 'holder'")->rows);
        $this->assertSame('target', $this->load($uuid)->links['main']->name);

        $this->assertError("UPDATE stdClass SET links['x'] = REF('00000000-0000-4000-8000-000000000000')", 'there is no such object');
        $this->assertError("UPDATE stdClass SET links['main'].name = 'x' WHERE name = 'holder'", "links['main'].name leads into a referenced object");
        $this->assertError("UPDATE stdClass REMOVE links['all'][0].name WHERE name = 'holder'", 'leads into a referenced object');
        $this->assertSame([['target']], $this->oql->query("SELECT name FROM stdClass WHERE UUID = ?", [$target])->rows);
    }

    public function testADryRunWritesNothing(): void
    {
        $uuid = $this->object(['tags' => ['a']]);
        $result = $this->oql->query("UPDATE stdClass SET tags[] = 'b' REMOVE other['x']", [], true);
        $this->assertSame([$uuid], $result->column(0));
        $this->assertSame(['a'], $this->dataOf($uuid)['tags']);
    }

    public function testErrors(): void
    {
        $fruit = new Category('Fruit');
        $this->storage->store(new Product('Apple', 'red', 1.5, ['red'], $fruit));
        $this->storage->store(new Product('Banana', 'yellow', 2, [], $fruit));
        $this->object(['created' => new \DateTimeImmutable('2026-01-01')]);

        $errors = [
            "UPDATE Product SET name['x'] = 1" => "name['x']: there is the text '",
            "UPDATE Product SET active.x = 1" => 'active.x: there is TRUE, not a list or map',
            "UPDATE Product SET category.title = 'x'" => 'category.title leads into a referenced object',
            "UPDATE Product SET tags[-1] = 'x'" => 'tags[-1]: there is no element [-1] in a list of 0',
            "UPDATE Product SET tags[0] = tags" => "tags[0] of Tests\\melia\\ObjectStorage\\Search\\Product: lists and objects can't be written yet",
            "UPDATE stdClass SET created.x = 1" => 'created.x leads into a date',
            "UPDATE Product SET tags[0] = 'a', tags[0] = 'b'" => 'tags[0] and tags[0] may be the same',
            "UPDATE Product SET tags[0] = 'a', tags['0'] = 'b'" => "tags[0] and tags['0'] may be the same",
            "UPDATE Product SET tags[0] = 'a' REMOVE tags.*" => 'tags[0] and tags.* may be the same',
            "UPDATE Product SET tags = [] " => 'Unexpected',
            "UPDATE Product SET tags = 'x', tags[0] = 'y'" => 'tags and tags[0] may be the same',
            "UPDATE Product SET tags[0].a = 'x', tags[-1] = 'y'" => 'tags[0].a and tags[-1] may be the same',
            "UPDATE Product SET tags[] = 'x', tags[0] = 'y'" => 'tags[] and tags[0] may be the same',
            "UPDATE Product SET name = 'a', name = 'b'" => 'A property is set twice',
            "UPDATE Product REMOVE tags" => 'REMOVE takes keys and elements, e.g. REMOVE tags[0]',
            "UPDATE Product REMOVE tags[]" => '[] appends an element: it can only be set, not removed',
            "UPDATE Product WHERE name = 'x'" => 'SET or REMOVE expected',
            "UPDATE Product SET name[0] = 'x' WHERE name = 'Banana'" => "there is the text 'Banana'",
        ];
        foreach ($errors as $query => $message) {
            $this->assertError($query, $message);
        }

        // the type of the property: a list or map only where it may be one
        $this->oql->query("UPDATE Product SET tags[] = 'new', price = NULL WHERE name = 'Banana'");
        $this->assertSame([['Apple', ['red']], ['Banana', ['new']]], $this->oql->query('SELECT name, tags FROM Product ORDER BY name')->rows);
        $this->assertError("UPDATE Product SET description = 'x' REMOVE description['x']", 'description and description[\'x\'] may be the same');
    }

    public function testTheTypeOfThePropertyIsChecked(): void
    {
        $this->storage->store(new Typed());
        $this->assertError("UPDATE `Tests\\melia\\ObjectStorage\\Query\\Typed` SET number['x'] = 1", "number['x']: there is the number 0, not a list or map");
        $this->assertError("UPDATE `Tests\\melia\\ObjectStorage\\Query\\Typed` SET maybe['x'] = 1", 'maybe of Tests\melia\ObjectStorage\Query\Typed is ?int, not a map');
        $this->oql->query("UPDATE `Tests\\melia\\ObjectStorage\\Query\\Typed` SET list['a'] = 1, iter[] = 2, any['b'] = 3");
        $loaded = $this->load($this->oql->query('SELECT UUID FROM Typed')->rows[0][0]);
        $this->assertSame(['a' => 1], $loaded->list);
        $this->assertSame([2], $loaded->iter);
        $this->assertSame(['b' => 3], $loaded->any);
    }
}

class Typed
{
    public int $number = 0;
    public ?int $maybe = null;
    public array $list = [];
    public iterable $iter = [];
    public mixed $any = null;
}
