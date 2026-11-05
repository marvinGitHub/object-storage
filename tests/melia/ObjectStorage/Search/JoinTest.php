<?php

namespace Tests\melia\ObjectStorage\Search;

use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\Search\DocumentBuilder;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\Index;
use melia\ObjectStorage\Search\Index\Manager;
use melia\ObjectStorage\Search\Request;
use Tests\melia\ObjectStorage\TestCase;

/**
 * Paths into referenced objects (joins when indexing): reading them, and updating the documents built from a
 * referenced object when it changes.
 */
class JoinTest extends TestCase
{
    private Manager $search;

    protected function setUp(): void
    {
        parent::setUp();
        $this->search = new Manager($this->storage, [new ArrayEngine()]);
    }

    public function tearDown(): void
    {
        $this->search->detach();
        parent::tearDown();
    }

    private function createIndex(): Index
    {
        return $this->search->create(new Definition('products', Product::class, [
            new Field('name', 'name'),
            new Field('category', 'category', searchable: false, filterable: true),
            new Field('categoryTitle', 'category.title'),
            new Field('parentTitle', 'category.parent.title'),
            new Field('labels', 'labels.title', FieldType::STRING_ARRAY, filterable: true),
        ]));
    }

    /**
     * @return string[] UUIDs of the hits
     */
    private function find(Index $index, string $query = '', ?string $filter = null): array
    {
        $uuids = $index->search(new Request(query: $query, filter: $filter, hitsPerPage: 1000))->getUuids();
        sort($uuids);
        return $uuids;
    }

    private function referenceOf(string $uuid, string $property): string
    {
        return json_decode(file_get_contents($this->storage->getFilePathData($uuid)), true)[$property]['__reference'];
    }

    public function testPathsLeadIntoReferencedObjects(): void
    {
        $food = new Category('Food');
        $fruit = new Category('Fruit', $food);
        $apple = $this->storage->store(new Product('Apple', category: $fruit, labels: [new Tag('red'), new Tag('sweet')]));
        $chair = $this->storage->store(new Product('Chair', category: new Category('Furniture')));
        $plain = $this->storage->store(new Product('Plain'));

        $index = $this->createIndex();
        $index->build();

        $this->assertSame([$apple], $this->find($index, 'fruit'), 'the title of the category');
        $this->assertSame([$apple], $this->find($index, 'food'), 'the title of the parent of the category');
        $this->assertSame([$apple], $this->find($index, 'sweet'), 'the titles of all labels');
        $this->assertSame([$chair], $this->find($index, 'furniture'));

        $fruitUuid = $this->referenceOf($apple, 'category');
        $this->assertSame([$apple], $this->find($index, '', "category = '$fruitUuid'"), 'a reference alone is its UUID');

        $hit = $index->search(new Request(filter: "uuid = '$apple'"))->hits[0];
        $this->assertSame('Fruit', $hit->document['categoryTitle']);
        $this->assertSame('Food', $hit->document['parentTitle']);
        $this->assertSame(['red', 'sweet'], $hit->document['labels']);
        $this->assertCount(4, $hit->document['referencedUuids'], 'the category, its parent and two labels');
        $this->assertContains($fruitUuid, $hit->document['referencedUuids']);

        $plainHit = $index->search(new Request(filter: "uuid = '$plain'"))->hits[0];
        $this->assertNull($plainHit->document['categoryTitle']);
        $this->assertSame([], $plainHit->document['referencedUuids']);

        $this->assertSame([Category::class, Tag::class], $index->getJoinedClasses());
    }

    public function testChangingAReferencedObjectUpdatesTheDocumentsBuiltFromIt(): void
    {
        $fruit = new Category('Fruit', new Category('Food'));
        $apple = $this->storage->store(new Product('Apple', category: $fruit));
        $banana = $this->storage->store(new Product('Banana', category: $fruit));
        $chair = $this->storage->store(new Product('Chair', category: new Category('Furniture')));

        $index = $this->createIndex();
        $index->build();
        $this->search->attach();

        // the category itself, not the products
        $fruitUuid = $this->referenceOf($apple, 'category');
        $category = $this->storage->load($fruitUuid);
        $category->title = 'Produce';
        $this->storage->store($category, $fruitUuid);

        $this->assertSame([], $this->find($index, 'fruit'));
        $expected = [$apple, $banana];
        sort($expected);
        $this->assertSame($expected, $this->find($index, 'produce'));
        $this->assertSame([$chair], $this->find($index, 'furniture'), 'others unchanged');

        // two references away
        $parentUuid = $this->referenceOf($fruitUuid, 'parent');
        $parent = $this->storage->load($parentUuid);
        $parent->title = 'Groceries';
        $this->storage->store($parent, $parentUuid);

        $this->assertSame($expected, $this->find($index, 'groceries'));
        $this->assertSame(0, $index->countPendingChanges());

        // deleted: the documents have no value anymore
        $this->storage->delete($parentUuid);
        $this->assertSame([], $this->find($index, 'groceries'));
        $this->assertSame($expected, $this->find($index, 'produce'), 'still found by the category');
    }

    public function testWithoutAutoSyncTheNextSyncUpdatesThem(): void
    {
        $fruit = new Category('Fruit');
        $apple = $this->storage->store(new Product('Apple', category: $fruit));

        $index = $this->createIndex();
        $index->build();
        $this->search->attach(autoSync: false);

        $fruitUuid = $this->referenceOf($apple, 'category');
        $category = $this->storage->load($fruitUuid);
        $category->title = 'Produce';
        $this->storage->store($category, $fruitUuid);

        $this->assertSame(1, $index->countPendingChanges(), 'the changed category');
        $this->assertSame([$apple], $this->find($index, 'fruit'), 'not yet');

        $this->assertSame(1, $this->search->sync()['products'], 'the product built from it');
        $this->assertSame([$apple], $this->find($index, 'produce'));
        $this->assertSame(0, $index->countPendingChanges());
    }

    public function testObjectsOfOtherClassesAreIgnoredUntilAnIndexReadsThem(): void
    {
        $apple = $this->storage->store(new Product('Apple', category: new Category('Fruit')));
        $index = $this->search->create(new Definition('products', Product::class, [new Field('name', 'name')]));
        $index->build();
        $this->search->attach(autoSync: false);

        $this->assertSame([], $index->getJoinedClasses());
        $this->storage->store(new Category('Vegetables'));
        $this->assertSame(0, $index->countPendingChanges(), 'no document is built from categories');

        $this->assertSame([$apple], $this->find($index, 'apple'));
    }

    public function testAllDocumentsBuiltFromAnObjectAreFound(): void
    {
        // more than the engine is asked for at once
        $fruit = new Category('Fruit');
        $this->storage->store(new Product('Product 0', category: $fruit));
        $fruitUuid = null;
        $products = [];
        foreach (range(1, 600) as $i) {
            $products[] = $uuid = $this->storage->store(new Product('Product ' . $i, category: $fruit));
            $fruitUuid ??= $this->referenceOf($uuid, 'category');
        }

        $index = $this->createIndex();
        $index->build(batchSize: 500);
        $this->search->attach(autoSync: false);

        $category = $this->storage->load($fruitUuid);
        $category->title = 'Produce';
        $this->storage->store($category, $fruitUuid);

        // a limited sync applies some, the next one the others
        $this->assertSame(300, $index->sync(maxChanges: 300));
        $this->assertSame(601 - 300, $index->countPendingChanges());
        $this->assertSame(601 - 300, $index->sync());
        $this->assertSame(601, $index->search(new Request('produce', hitsPerPage: 1))->totalHits);
        $this->assertSame([], $this->find($index, 'fruit'));
    }

    public function testFieldsAreSuggestedFromReferencedObjects(): void
    {
        $apple = $this->storage->store(new Product('Apple', category: new Category('Fruit', new Category('Food', new Category('All'))), labels: [new Tag('red')]));
        $data = json_decode(file_get_contents($this->storage->getFilePathData($apple)), true);
        $follow = function (string $uuid): ?array {
            $object = $this->storage->readRaw($uuid);
            return [json_decode($object->data, true), $object->metadata->getReservedReferenceName(), $object->metadata->getClassName()];
        };

        $suggested = [];
        foreach (DocumentBuilder::suggestFields($data, '__reference', $follow) as $field) {
            $suggested[$field->path] = $field;
        }

        $this->assertArrayHasKey('category', $suggested, 'the UUID of the category');
        $this->assertSame(FieldType::STRING, $suggested['category.title']->type);
        $this->assertTrue($suggested['category.title']->searchable);
        $this->assertSame('category_title', $suggested['category.title']->name);
        $this->assertArrayHasKey('category.parent.title', $suggested, 'two references away');
        $this->assertArrayHasKey('category.parent.parent', $suggested, 'the UUID of the grandparent');
        $this->assertArrayNotHasKey('category.parent.parent.title', $suggested, 'not further');
        $this->assertSame(FieldType::STRING_ARRAY, $suggested['labels']->type);
        $this->assertSame(FieldType::STRING_ARRAY, $suggested['labels.title']->type, 'the titles of all labels');

        $this->assertArrayNotHasKey('category.title', array_flip(array_map(
            static fn(Field $field): string => $field->path,
            DocumentBuilder::suggestFields($data, '__reference')
        )), 'not without reading referenced objects');

        // from several objects: a value missing in the first one is suggested from the others
        $plain = json_decode(file_get_contents($this->storage->getFilePathData($this->storage->store(new Product('Plain')))), true);
        $merged = [];
        foreach (DocumentBuilder::suggestFieldsFrom([[$plain, '__reference'], [$data, '__reference']], $follow) as $field) {
            $merged[$field->path] = $field;
        }
        $this->assertFalse($merged['category']->searchable, 'a reference, as in the second object');
        $this->assertArrayHasKey('category.title', $merged);
    }

    public function testAPathFollowsALimitedNumberOfReferences(): void
    {
        // a -> b -> c -> d -> e, each {"next": reference, "title": ...}
        $objects = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $i => $name) {
            $objects[$name] = ['title' => strtoupper($name), 'next' => ['__reference' => sprintf('00000000-0000-4000-8000-%012d', $i + 1)]];
        }
        $byUuid = [];
        foreach (array_values($objects) as $i => $object) {
            $byUuid[sprintf('00000000-0000-4000-8000-%012d', $i)] = $object;
        }
        $reads = [];
        $builder = new DocumentBuilder(new Definition('chain', 'Chain', [
            new Field('three', 'next.next.next.title'),
            new Field('four', 'next.next.next.next.title'),
        ]), function (string $uuid) use ($byUuid, &$reads): ?array {
            $reads[] = $uuid;
            return isset($byUuid[$uuid]) ? [$byUuid[$uuid], '__reference', 'Chain'] : null;
        });

        $document = $builder->build('00000000-0000-4000-8000-000000000000', $objects['a'], new Metadata());

        $this->assertSame('D', $document['three']);
        $this->assertNull($document['four'], 'at most ' . DocumentBuilder::MAX_REFERENCES_PER_PATH . ' references');
        $this->assertCount(3, $document['referencedUuids']);
        $this->assertSame(array_values(array_unique($reads)), $reads, 'each object is read once');
        $this->assertSame(['Chain'], $builder->getJoinedClasses());
    }
}
