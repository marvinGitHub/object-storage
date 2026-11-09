<?php

namespace Tests\melia\ObjectStorage\Path;

use melia\ObjectStorage\Path\Exception\InvalidPathException;
use melia\ObjectStorage\Path\Path;
use melia\ObjectStorage\Path\PathSegment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PathTest extends TestCase
{
    public function testParsing(): void
    {
        $this->assertSame(['address', 'city'], Path::parse('address.city'));
        $this->assertEquals(['friends', PathSegment::wildcard(), 'name'], Path::parse('friends.*.name'));
        $this->assertEquals(['friends', PathSegment::wildcard(), 'name'], Path::parse('friends[*].name'));
        $this->assertEquals(['friends', PathSegment::index(0), 'name'], Path::parse('friends[0].name'));
        $this->assertEquals(['friends', PathSegment::index(-1)], Path::parse('friends[-1]'));
        $this->assertEquals(['friends', PathSegment::key('alex'), 'name'], Path::parse("friends['alex'].name"));
        $this->assertEquals(['friends', PathSegment::key('alex'), 'name'], Path::parse('friends["alex"].name'));
        $this->assertEquals(['a', PathSegment::key("o'neil.x"), PathSegment::index(2)], Path::parse("a['o''neil.x'][2]"));
        $this->assertEquals([PathSegment::key('a.b'), 'c'], Path::parse("['a.b'].c"));
        $this->assertEquals(['matrix', PathSegment::index(0), PathSegment::index(1)], Path::parse('matrix[0][1]'));
    }

    public function testWritingTheSameWay(): void
    {
        foreach (['address.city', 'friends.*.name', 'friends[0].name', "friends['alex'].name", "['a.b'].c", 'm[0][-1].*'] as $path) {
            $this->assertSame($path, Path::toString(Path::parse($path)));
        }
        $this->assertSame('friends.*.name', Path::toString(Path::parse('friends[*].name')));
        $this->assertSame("friends['alex'].name", Path::toString(Path::parse('friends["alex"].name')));
        $this->assertSame("a['b.c']['*']", Path::toString(['a', 'b.c', '*']), 'names that aren\'t plain as keys');
    }

    public function testWildcards(): void
    {
        $this->assertTrue(Path::hasWildcard(Path::parse('friends.*.name')));
        $this->assertFalse(Path::hasWildcard(Path::parse('friends[0].name')));
        $this->assertTrue(Path::isPlain(Path::parse('a.b')));
        $this->assertFalse(Path::isPlain(Path::parse('a[0]')));
    }

    #[DataProvider('invalidPaths')]
    public function testInvalidPaths(string $path): void
    {
        $this->expectException(InvalidPathException::class);
        Path::parse($path);
    }

    public static function invalidPaths(): array
    {
        return [[''], ['.a'], ['a.'], ['a..b'], ['a[0'], ['a[x]'], ["a['x"], ['a]'], ['a[0]b'], ['a[1.5]'], ['a.[0]']];
    }
}
