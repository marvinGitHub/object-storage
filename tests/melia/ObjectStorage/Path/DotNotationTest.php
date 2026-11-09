<?php

namespace Tests\melia\ObjectStorage\Path;

use Loupe\Loupe\LoupeFactory;
use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Query\Oql;
use melia\ObjectStorage\Search\Exception\InvalidIndexDefinitionException;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\Manager as SearchManager;
use melia\ObjectStorage\Search\Loupe\LoupeEngine;
use melia\ObjectStorage\Search\Request;
use melia\ObjectStorage\Unique\Definition as UniqueDefinition;
use melia\ObjectStorage\Unique\Manager as UniqueManager;
use Tests\melia\ObjectStorage\Search\ArrayEngine;
use Tests\melia\ObjectStorage\TestCase;

/**
 * Paths with *, [position] and ['key'] in search indexes, unique indexes and OQL, also across referenced objects:
 * friends.*.name, friends[0].name, friendsByName['alex'].name, friends.*.friends.*.name.
 */
class DotNotationTest extends TestCase
{
    /** @var array<string, string> name => UUID */
    private array $uuids = [];
    private ?SearchManager $search = null;

    protected function setUp(): void
    {
        parent::setUp();
        $alex = new Member('Alex', emails: ['alex@example.com', 'a@example.com']);
        $bob = new Member('Bob', emails: ['bob@example.com']);
        $carol = new Member('Carol', friends: [$alex, $bob], friendsByName: ['alex' => $alex, 'bob' => $bob]);
        $dave = new Member('Dave', friends: [$carol]);
        $this->uuids['Carol'] = $this->storage->store($carol);
        $this->uuids['Dave'] = $this->storage->store($dave);
        $this->uuids['Erin'] = $this->storage->store(new Member('Erin'));
        $oql = new Oql($this->storage);
        foreach ($oql->query('SELECT name, UUID FROM Member')->rows as [$name, $uuid]) {
            $this->uuids[$name] = $uuid;
        }
    }

    public function tearDown(): void
    {
        $this->search?->detach();
        $this->search = null;
        parent::tearDown();
    }

    private function names(string $query, array $parameters = [], ?Oql $oql = null): array
    {
        return array_column(($oql ?? new Oql($this->storage))->query($query, $parameters)->rows, 0);
    }

    // ---- search indexes ----

    public function testIndexFieldsWithPathsIntoListsOfReferencedObjects(): void
    {
        $this->search = new SearchManager($this->storage, [new ArrayEngine()]);
        $index = $this->search->create(new Definition('members', Member::class, [
            new Field('name', 'name', filterable: true),
            new Field('friendNames', 'friends.*.name', FieldType::STRING_ARRAY, filterable: true),
            new Field('firstFriend', 'friends[0].name', filterable: true),
            new Field('lastFriend', 'friends[-1].name', filterable: true),
            new Field('alex', "friendsByName['alex'].name", filterable: true),
            new Field('friendsOfFriends', 'friends.*.friends.*.name', FieldType::STRING_ARRAY, filterable: true),
            new Field('firstEmailOfFriends', 'friends.*.emails[0]', FieldType::STRING_ARRAY, filterable: true),
        ]));
        $index->build();

        $carol = $index->search(new Request(filter: "uuid = '{$this->uuids['Carol']}'"))->hits[0]->document;
        $this->assertSame(['Alex', 'Bob'], $carol['friendNames']);
        $this->assertSame('Alex', $carol['firstFriend']);
        $this->assertSame('Bob', $carol['lastFriend']);
        $this->assertSame('Alex', $carol['alex']);
        $this->assertNull($carol['friendsOfFriends']);
        $this->assertSame(['alex@example.com', 'bob@example.com'], $carol['firstEmailOfFriends']);
        $this->assertCount(2, $carol['referencedUuids'], 'Alex and Bob');

        $dave = $index->search(new Request(filter: "uuid = '{$this->uuids['Dave']}'"))->hits[0]->document;
        $this->assertSame(['Carol'], $dave['friendNames']);
        $this->assertSame(['Alex', 'Bob'], $dave['friendsOfFriends'], 'across two levels of referenced objects');
        $this->assertNull($dave['alex']);

        $erin = $index->search(new Request(filter: "uuid = '{$this->uuids['Erin']}'"))->hits[0]->document;
        $this->assertNull($erin['friendNames']);
        $this->assertNull($erin['firstFriend']);
    }

    public function testInvalidFieldPath(): void
    {
        $this->expectException(InvalidIndexDefinitionException::class);
        new Field('f', 'friends[x].name');
    }

    public function testChangingAFriendUpdatesTheDocument(): void
    {
        $this->search = new SearchManager($this->storage, [new ArrayEngine()]);
        $index = $this->search->create(new Definition('members', Member::class, [
            new Field('friendNames', 'friends.*.name', FieldType::STRING_ARRAY, filterable: true),
        ]));
        $index->build();
        $this->search->attach();

        $alex = $this->storage->load($this->uuids['Alex']);
        $alex->name = 'Alexander';
        $this->storage->store($alex, $this->uuids['Alex']);
        $this->search->sync();

        $carol = $index->search(new Request(filter: "uuid = '{$this->uuids['Carol']}'"))->hits[0]->document;
        $this->assertSame(['Alexander', 'Bob'], $carol['friendNames']);
    }

    // ---- unique indexes ----

    public function testUniqueIndexOnAPosition(): void
    {
        $unique = new UniqueManager($this->storage);
        $unique->create(new UniqueDefinition('member-email', Member::class, 'emails[0]'));
        $this->assertSame($this->uuids['Alex'], $unique->findOwner('member-email', 'alex@example.com'));
        $this->assertNull($unique->findOwner('member-email', 'a@example.com'), 'only the first email');

        $oql = new Oql($this->storage, unique: $unique);
        $this->assertSame(['Alex'], $this->names("SELECT name FROM Member WHERE emails[0] = 'alex@example.com'", oql: $oql));
        $this->assertSame('unique index', $oql->query("EXPLAIN SELECT name FROM Member WHERE emails[0] = 'alex@example.com'")->rows[0][2]);
    }

    // ---- OQL ----

    public function testWhereAnyElement(): void
    {
        $this->assertSame(['Carol'], $this->names("SELECT name FROM Member WHERE friends.*.name = 'Alex'"));
        $this->assertSame(['Carol'], $this->names("SELECT name FROM Member WHERE friends[*].name = 'Bob'"));
        $this->assertSame(['Carol'], $this->names("SELECT m.name FROM Member m WHERE m.friends.*.name IN ('Bob', 'Zoe')"));
        $this->assertSame(['Dave'], $this->names("SELECT name FROM Member WHERE friends.*.name LIKE 'C%'"));
        $this->assertSame(['Carol'], $this->names("SELECT name FROM Member WHERE friends.*.name BETWEEN 'A' AND 'Az'"));
        $this->assertSame(['Carol'], $this->names("SELECT name FROM Member WHERE 'Alex' = friends.*.name"));
        $this->assertSame(['Carol', 'Dave'], $this->names("SELECT name FROM Member WHERE friends.*.name > 'B' ORDER BY name"));
    }

    public function testNegatedConditionsHoldForNoElement(): void
    {
        $this->assertSame(['Alex', 'Bob', 'Dave', 'Erin'], $this->names("SELECT name FROM Member WHERE friends.*.name != 'Alex' ORDER BY name"));
        $this->assertSame(['Alex', 'Bob', 'Dave', 'Erin'], $this->names("SELECT name FROM Member WHERE friends.*.name NOT IN ('Alex') ORDER BY name"));
        $this->assertSame(['Alex', 'Bob', 'Carol', 'Erin'], $this->names("SELECT name FROM Member WHERE friends.*.name NOT LIKE 'C%' ORDER BY name"));
        $this->assertSame(['Dave'], $this->names("SELECT name FROM Member WHERE NOT friends.*.name != 'Carol'"));
    }

    public function testPositionsAndKeys(): void
    {
        $this->assertSame(['Carol'], $this->names("SELECT name FROM Member WHERE friends[0].name = 'Alex'"));
        $this->assertSame(['Carol'], $this->names("SELECT name FROM Member WHERE friends[-1].name = 'Bob'"));
        $this->assertSame([], $this->names("SELECT name FROM Member WHERE friends[5].name = 'Alex'"));
        $this->assertSame(['Carol'], $this->names("SELECT name FROM Member WHERE friendsByName['alex'].name = 'Alex'"));
        $this->assertSame(['Carol'], $this->names("SELECT m.name FROM Member m WHERE m.friendsByName['bob'].name = 'Bob'"));
        $this->assertSame([['Alex', 'alex@example.com']], (new Oql($this->storage))->query("SELECT friends[0].name, friends[0].emails[0] FROM Member WHERE name = 'Carol'")->rows);
        $this->assertSame([$this->uuids['Alex']], $this->names("SELECT friends[0].UUID FROM Member WHERE name = 'Carol'"));
    }

    public function testAcrossSeveralObjects(): void
    {
        $this->assertSame(['Dave'], $this->names("SELECT name FROM Member WHERE friends.*.friends.*.name = 'Bob'"));
        $this->assertSame(['Dave'], $this->names("SELECT name FROM Member WHERE friends[0].friendsByName['alex'].name = 'Alex'"));
        $this->assertSame(['Dave'], $this->names("SELECT name FROM Member WHERE friends.*.friends[1].emails.* = 'bob@example.com'"));
    }

    public function testSelectingLists(): void
    {
        $oql = new Oql($this->storage);
        $this->assertSame([[['Alex', 'Bob']]], $oql->query("SELECT friends.*.name FROM Member WHERE name = 'Carol'")->rows);
        $this->assertSame([[[$this->uuids['Alex'], $this->uuids['Bob']]]], $oql->query("SELECT friends.*.UUID FROM Member WHERE name = 'Carol'")->rows);
        $this->assertSame([[null]], $oql->query("SELECT friends.*.name FROM Member WHERE name = 'Erin'")->rows);
        $result = $oql->query("SELECT friends[0].name AS first, COUNT(*) AS n FROM Member GROUP BY friends[0].name ORDER BY first");
        $this->assertSame([[null, 3], ['Alex', 1], ['Carol', 1]], $result->rows);
    }

    public function testJoiningTheElementsOfAList(): void
    {
        $oql = new Oql($this->storage);
        $this->assertSame(
            [['Carol', 'Alex'], ['Carol', 'Bob'], ['Dave', 'Carol']],
            $oql->query('SELECT m.name, f.name FROM Member m JOIN Member f ON f = m.friends.* ORDER BY m.name, f.name')->rows,
        );
        $this->assertSame(
            [['Carol', 'Alex']],
            $oql->query("SELECT m.name, f.name FROM Member m JOIN Member f ON f.UUID = m.friendsByName['alex']")->rows,
        );
    }

    public function testInvalidBrackets(): void
    {
        foreach (['SELECT friends[x] FROM Member', 'SELECT friends[1.5] FROM Member', 'SELECT friends[0 FROM Member'] as $query) {
            try {
                (new Oql($this->storage))->query($query);
                $this->fail($query);
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSearchIndexForPathsWithWildcards(): void
    {
        if (!class_exists(LoupeFactory::class) || !extension_loaded('intl')) {
            $this->markTestSkipped('Loupe needs loupe/loupe and ext-intl');
        }
        $this->search = new SearchManager($this->storage, [new LoupeEngine()]);
        $this->search->create(new Definition('members', Member::class, [
            new Field('friendNames', 'friends.*.name', FieldType::STRING_ARRAY, searchable: false, filterable: true),
            new Field('firstFriend', 'friends[0].name', searchable: false, filterable: true),
        ]))->build();

        $oql = new Oql($this->storage, $this->search);
        $this->assertSame(['Carol'], $this->names("SELECT name FROM Member WHERE friends.*.name = 'Bob'", oql: $oql));
        $this->assertSame('search index', $oql->query("EXPLAIN SELECT name FROM Member WHERE friends.*.name = 'Bob'")->rows[0][2]);
        $this->assertSame('search index', $oql->query("EXPLAIN SELECT name FROM Member WHERE friends[*].name IN ('Bob', 'Alex')")->rows[0][2]);
        $this->assertSame(['Carol'], $this->names("SELECT name FROM Member WHERE friends[0].name = 'Alex'", oql: $oql));
        $this->assertSame('search index', $oql->query("EXPLAIN SELECT name FROM Member WHERE friends[0].name = 'Alex'")->rows[0][2]);
        // negated: not by the index
        $this->assertSame('all objects of the class', $oql->query("EXPLAIN SELECT name FROM Member WHERE friends.*.name != 'Bob'")->rows[0][2]);
    }
}
