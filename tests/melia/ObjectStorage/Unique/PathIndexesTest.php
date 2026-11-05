<?php

namespace Tests\melia\ObjectStorage\Unique;

use melia\ObjectStorage\Unique\Definition;
use melia\ObjectStorage\Unique\Exception\DuplicateValuesException;
use melia\ObjectStorage\Unique\Manager;
use melia\ObjectStorage\Unique\PathIndexes;
use Tests\melia\ObjectStorage\Search\Product;
use Tests\melia\ObjectStorage\TestCase;

/**
 * Unique indexes managed per path, as the "unique" flag of the fields of a search index in the viewer does.
 */
final class PathIndexesTest extends TestCase
{
    private Manager $unique;
    private PathIndexes $paths;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unique = new Manager($this->storage);
        $this->paths = new PathIndexes($this->unique);
    }

    public function testFlagsCreateAndDropIndexes(): void
    {
        $result = $this->paths->apply(Product::class, [
            'name' => ['unique' => true, 'name' => 'name'],
            'description' => ['unique' => false],
        ], 'Products');

        $this->assertSame(['created' => ['products-name'], 'dropped' => []], $result);
        $this->assertSame(['name'], array_keys($this->paths->forClass(Product::class)));

        // unchanged: nothing to do; unchecked: dropped
        $this->assertSame(['created' => [], 'dropped' => []], $this->paths->apply(Product::class, ['name' => ['unique' => true]], 'products'));
        $this->assertSame(['created' => [], 'dropped' => ['products-name']], $this->paths->apply(Product::class, ['name' => ['unique' => false]], 'products'));
        $this->assertSame([], $this->paths->forClass(Product::class));
    }

    public function testIndexesCreatedElsewhereAreRecognizedAndPathsNotGivenLeftAlone(): void
    {
        $this->unique->create(new Definition('sku', Product::class, 'name'));
        $this->unique->create(new Definition('combination', Product::class, ['name', 'price']));

        $this->assertSame(['name'], array_keys($this->paths->forClass(Product::class)), 'single paths only');
        $this->paths->apply(Product::class, ['price' => ['unique' => false]], 'products');
        $this->assertTrue($this->unique->has('sku'));
        $this->assertTrue($this->unique->has('combination'));
    }

    public function testChangingTheCaseHandlingReplacesTheIndexUnderItsName(): void
    {
        $this->paths->apply(Product::class, ['name' => ['unique' => true]], 'products');

        $result = $this->paths->apply(Product::class, ['name' => ['unique' => true, 'caseInsensitive' => true]], 'products');

        $this->assertSame(['created' => ['products-name'], 'dropped' => ['products-name']], $result);
        $this->assertTrue($this->unique->get('products-name')->caseInsensitive);
    }

    public function testAFailureUndoesTheCall(): void
    {
        $this->storage->store(new Product('Apple', 'Fruit'));
        $this->storage->store(new Product('Pear', 'Fruit'));
        $this->paths->apply(Product::class, ['name' => ['unique' => true]], 'products');

        try {
            $this->paths->apply(Product::class, [
                'name' => ['unique' => false],
                'price' => ['unique' => false],
                'description' => ['unique' => true], // shared: fails
            ], 'products');
            $this->fail('created over duplicates');
        } catch (DuplicateValuesException) {
        }

        $this->assertSame(['name'], array_keys($this->paths->forClass(Product::class)), 'the dropped index is back');
    }

    public function testNamesAreFree(): void
    {
        $this->unique->create(new Definition('products-name', Product::class, 'description'));

        $this->assertSame(['created' => ['products-name-2'], 'dropped' => []], $this->paths->apply(Product::class, ['name' => ['unique' => true]], 'products'));
    }
}
