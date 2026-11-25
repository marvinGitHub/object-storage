<?php

namespace Tests\melia\ObjectStorage\Search\Index;

use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\DocumentIndexer;
use melia\ObjectStorage\Search\Index\Generations;
use melia\ObjectStorage\Search\Request;
use Tests\melia\ObjectStorage\Search\ArrayEngine;
use Tests\melia\ObjectStorage\Search\Category;
use Tests\melia\ObjectStorage\Search\Product;
use Tests\melia\ObjectStorage\TestCase;

class DocumentIndexerTest extends TestCase
{
    private string $directory;
    private Generations $generations;
    private int $generation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = $this->createTemporaryDirectory() . DIRECTORY_SEPARATOR . 'products';
        $this->generations = new Generations($this->directory, 'products', new ArrayEngine(reject: static fn(array $document): ?string => 'Rejected' === $document['name'] ? 'rejected by the engine' : null), $this->storage);
        $this->generation = $this->generations->create(null, new Definition('products', Product::class, [
            new Field('name', 'name'),
            new Field('categoryTitle', 'category.title', filterable: true),
        ]));
    }

    public function tearDown(): void
    {
        $this->generations->closeAll();
        $this->tearDownDirectory(dirname($this->directory));
        parent::tearDown();
    }

    /**
     * @return string[]
     */
    private function indexed(): array
    {
        return $this->generations->open($this->generation)->search(new Request(hitsPerPage: 100))->getUuids();
    }

    public function testObjectsAreIndexedAsTheyAreStored(): void
    {
        $indexer = new DocumentIndexer($this->storage, $this->generations);
        $apple = $this->storage->store(new Product('Apple', category: new Category('Fruit')));
        $chair = $this->storage->store(new Product('Chair'));
        $category = $this->storage->store(new Category('Furniture'));

        $result = $indexer->apply($this->generation, [$apple, $chair, $category, $apple]);

        $this->assertSame(['failed' => 0, 'errors' => []], $result);
        $this->assertEqualsCanonicalizing([$apple, $chair], $this->indexed(), 'objects of another class are not indexed');
        $this->assertSame([Category::class], $indexer->takeJoinedClasses(), 'the classes of the referenced objects read');
        $this->assertSame([], $indexer->takeJoinedClasses(), 'taken once');

        $this->storage->delete($chair);
        $indexer->apply($this->generation, [$chair]);
        $this->assertSame([$apple], $this->indexed(), 'a deleted object is removed');
    }

    public function testObjectsThatFailAreRemovedAndReported(): void
    {
        $indexer = new DocumentIndexer($this->storage, $this->generations);
        $apple = $this->storage->store(new Product('Apple'));
        $rejected = $this->storage->store(new Product('Accepted'));
        $indexer->apply($this->generation, [$apple, $rejected]);

        $product = $this->storage->load($rejected);
        $product->name = 'Rejected';
        $this->storage->store($product);
        $result = $indexer->apply($this->generation, [$rejected]);

        $this->assertSame(1, $result['failed']);
        $this->assertSame([$rejected . ': rejected by the engine'], $result['errors']);
        $this->assertSame([$apple], $this->indexed(), 'no outdated version stays searchable');
    }

    public function testTheDocumentsBuiltFromReferencedObjectsAreFound(): void
    {
        $indexer = new DocumentIndexer($this->storage, $this->generations);
        $fruit = new Category('Fruit');
        $apple = $this->storage->store(new Product('Apple', category: $fruit));
        $banana = $this->storage->store(new Product('Banana', category: $fruit));
        $this->storage->store(new Product('Chair', category: new Category('Furniture')));
        $indexer->apply($this->generation, iterator_to_array($this->storage->list(Product::class), false));

        $fruitUuid = json_decode((string)file_get_contents($this->storage->getFilePathData($apple)), true)['category']['__reference'];

        $this->assertEqualsCanonicalizing([$apple, $banana], $indexer->referencing($this->generation, [$fruitUuid]));
        $this->assertSame([], $indexer->referencing($this->generation, ['unknown']));
    }
}
