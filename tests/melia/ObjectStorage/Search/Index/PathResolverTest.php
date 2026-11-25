<?php

namespace Tests\melia\ObjectStorage\Search\Index;

use melia\ObjectStorage\Search\Exception\UnknownPathException;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\PathResolver;
use melia\ObjectStorage\Search\Request;
use PHPUnit\Framework\TestCase;
use Tests\melia\ObjectStorage\Search\ArrayEngine;
use Tests\melia\ObjectStorage\Search\Product;

class PathResolverTest extends TestCase
{
    private function definition(): Definition
    {
        return new Definition('products', Product::class, [
            new Field('name', 'name'),
            new Field('categoryTitle', 'category.title', filterable: true, sortable: true),
            new Field('price', 'price', FieldType::NUMBER, filterable: true, sortable: true),
            // a path that is also the name of a field names that field
            new Field('other', 'name'),
        ]);
    }

    public function testPathsAreRenamedToTheNamesOfTheirFields(): void
    {
        $resolver = new PathResolver(new ArrayEngine(), 'products');

        $request = $resolver->resolve(new Request(
            filter: "category.title = 'Fruit'",
            sort: ['category.title:desc', 'price:asc'],
            facets: ['category.title'],
            searchOn: ['name'],
        ), $this->definition());

        $this->assertSame("categoryTitle = 'Fruit'", $request->filter);
        $this->assertSame(['categoryTitle:desc', 'price:asc'], $request->sort);
        $this->assertSame(['categoryTitle'], $request->facets);
        $this->assertSame(['name'], $request->searchOn);
    }

    public function testARequestNamingNoPathIsReturnedAsItIs(): void
    {
        $resolver = new PathResolver(new ArrayEngine(), 'products');
        $request = new Request(query: 'apple', filter: "price = '3'", sort: ['name:asc']);

        $this->assertSame($request, $resolver->resolve($request, $this->definition()));
    }

    public function testAPathThatIsNotThePathOfAFieldFails(): void
    {
        $resolver = new PathResolver(new ArrayEngine(), 'products');

        $this->expectException(UnknownPathException::class);
        $this->expectExceptionMessage('Search index "products" has no field with the path "category.parent.title", "owner.name"');
        $resolver->resolve(new Request(filter: "category.parent.title = 'Food'", sort: ['owner.name:asc']), $this->definition());
    }
}
