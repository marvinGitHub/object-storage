<?php

namespace Tests\melia\ObjectStorage\Upgrade;

use DateTimeInterface;
use melia\ObjectStorage\LazyLoadReference;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Query\Oql;
use melia\ObjectStorage\Unique\Definition;
use melia\ObjectStorage\Unique\Exception\UniqueConstraintViolationException;
use melia\ObjectStorage\Unique\Manager;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\melia\ObjectStorage\TestCase;
use Tests\melia\ObjectStorage\Upgrade\Fixture\Author;
use Tests\melia\ObjectStorage\Upgrade\Fixture\CacheEntry;
use Tests\melia\ObjectStorage\Upgrade\Fixture\Category;
use Tests\melia\ObjectStorage\Upgrade\Fixture\Note;
use Tests\melia\ObjectStorage\Upgrade\Fixture\Product;

/**
 * Opens a storage written by version 0.0.10 (tests/fixtures/upgrade/storage-0.0.10, see generate-0.0.10.php there)
 * with this version: everything stored before has to load as it was, and writing, indexing and querying have to work
 * on it. The fixture is copied for every test, so it stays as 0.0.10 wrote it.
 */
final class UpgradeFrom0010Test extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../../fixtures/upgrade/storage-0.0.10';

    private const string ROOT = '00000000-0000-4000-8000-000000000001';
    private const string PERIPHERALS = '00000000-0000-4000-8000-000000000002';
    private const string KEYBOARDS = '00000000-0000-4000-8000-000000000003';
    private const string ADA = '00000000-0000-4000-8000-000000000010';
    private const string GRACE = '00000000-0000-4000-8000-000000000011';
    private const string KEYBOARD = '00000000-0000-4000-8000-000000000020';
    private const string MOUSE = '00000000-0000-4000-8000-000000000021';
    private const string CABLE = '00000000-0000-4000-8000-000000000022';
    private const string NOTE = '00000000-0000-4000-8000-000000000030';
    private const string VALID = '00000000-0000-4000-8000-000000000040';
    private const string EXPIRED = '00000000-0000-4000-8000-000000000041';
    private const string DELETED = '00000000-0000-4000-8000-000000000050';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = $this->createTemporaryDirectory();
        $this->storageDirs[] = $this->dir;
        $this->copyDirectory(self::FIXTURE, $this->dir);
        $this->storage = new ObjectStorage($this->dir);
    }

    public function testTheFixtureIsAsWrittenBy0010(): void
    {
        $metadata = $this->storage->loadMetadata(self::KEYBOARD);

        $this->assertNotNull($metadata);
        $this->assertSame(1, $metadata->getVersion(), 'the fixture has to hold metadata of format version 1');
        $this->assertSame(0, $metadata->getRevision(), 'objects stored before revisions existed have revision 0');
        $this->assertStringContainsString('"__reference"', (string)file_get_contents($this->storage->getFilePathData(self::KEYBOARD)));
    }

    public function testClassesAndObjectsAreListed(): void
    {
        $this->assertEqualsCanonicalizing(
            [Category::class, Author::class, Product::class, Note::class, CacheEntry::class],
            array_values($this->storage->getClassNames()),
        );
        $this->assertEqualsCanonicalizing([self::KEYBOARD, self::MOUSE, self::CABLE], iterator_to_array($this->storage->list(Product::class), false));
        $this->assertEqualsCanonicalizing([self::ROOT, self::PERIPHERALS, self::KEYBOARDS], iterator_to_array($this->storage->list(Category::class), false));
    }

    public function testObjectsLoadWithTheirValues(): void
    {
        $keyboard = $this->storage->load(self::KEYBOARD);

        $this->assertInstanceOf(Product::class, $keyboard);
        $this->assertSame(self::KEYBOARD, $keyboard->getUUID());
        $this->assertSame('Keyboard', $keyboard->name);
        $this->assertSame(49.9, $keyboard->price);
        $this->assertSame(12, $keyboard->stock);
        $this->assertTrue($keyboard->active);
        $this->assertSame(['input', 'usb'], $keyboard->tags);
        $this->assertSame(['width' => 44.5, 'depth' => 13, 'unit' => 'cm', 'colors' => ['black', 'white']], $keyboard->dimensions);
        $this->assertSame("Mechanical, with \"quotes\", umlauts (äöü) and a\nnew line", $keyboard->description);

        $mouse = $this->storage->load(self::MOUSE);
        $this->assertInstanceOf(Product::class, $mouse);
        $this->assertSame(0, $mouse->stock);
        $this->assertFalse($mouse->active);
        $this->assertSame([], $mouse->tags);
        $this->assertNull($mouse->description);
        $this->assertSame(4.0, $this->storage->load(self::CABLE)?->price);

        $this->assertFalse((bool)$this->storage->getStateHandler()?->safeModeEnabled(), 'loading old data must not enable safe mode');
    }

    public function testReferencesAreFollowed(): void
    {
        $keyboard = $this->storage->load(self::KEYBOARD);
        $this->assertInstanceOf(Product::class, $keyboard);

        // a chain of references, loaded lazily
        $this->assertInstanceOf(LazyLoadReference::class, $keyboard->category);
        $this->assertSame('Keyboards', $keyboard->category->title);
        $this->assertSame('Peripherals', $keyboard->category->parent->title);
        $this->assertSame('Hardware', $keyboard->category->parent->parent->title);
        $this->assertNull($keyboard->category->parent->parent->parent);

        // lists of references
        $this->assertCount(2, $keyboard->related);
        $this->assertSame(['Mouse', 'Cable'], array_map(static fn($product): string => $product->name, $keyboard->related));

        // a cycle: the author's favorite is the keyboard, which references the author
        $this->assertSame('Ada', $keyboard->author->name);
        $this->assertSame(self::KEYBOARD, $keyboard->author->favorite->getUUID());
        $this->assertSame('ada@example.com', $keyboard->author->favorite->author->email);
    }

    public function testUnserializeReceivesTheReferencedObject(): void
    {
        $note = $this->storage->load(self::NOTE);

        $this->assertInstanceOf(Note::class, $note);
        $this->assertSame('Remember the keyboard layout', $note->text);
        $this->assertIsObject($note->receivedAuthor, '__unserialize() has to receive the object, not ["__reference" => uuid]');
        $this->assertSame('Grace', $note->author->name);
        $this->assertSame(self::GRACE, $note->author->getUUID());
    }

    public function testExpirationsAreKept(): void
    {
        $expiration = $this->storage->getExpiration(self::VALID);
        $this->assertInstanceOf(DateTimeInterface::class, $expiration);
        $this->assertSame('2100-01-01', $expiration->format('Y-m-d'));
        $this->assertFalse($this->storage->expired(self::VALID));
        $this->assertSame('still there', $this->storage->load(self::VALID)?->value);

        $this->assertTrue($this->storage->expired(self::EXPIRED));
        $this->assertNull($this->storage->getExpiration(self::ROOT));
    }

    public function testDeletedObjectsStayDeleted(): void
    {
        $this->assertFalse($this->storage->exists(self::DELETED));
        $this->assertNull($this->storage->load(self::DELETED));
    }

    public function testStoringUpgradesTheMetadata(): void
    {
        $mouse = $this->storage->load(self::MOUSE, true);
        $this->assertInstanceOf(Product::class, $mouse);
        $mouse->stock = 5;
        $this->storage->store($mouse);

        $metadata = $this->storage->loadMetadata(self::MOUSE);
        $this->assertNotNull($metadata);
        $this->assertSame(Metadata::VERSION, $metadata->getVersion());
        $this->assertSame(1, $metadata->getRevision());

        // the references written by 0.0.10 and those written now resolve alike, also in a new process
        $reopened = new ObjectStorage($this->dir);
        $loaded = $reopened->load(self::MOUSE);
        $this->assertInstanceOf(Product::class, $loaded);
        $this->assertSame(5, $loaded->stock);
        $this->assertSame('Grace', $loaded->author->name);
        $this->assertSame('Peripherals', $loaded->category->title);
        $this->assertSame('Keyboard', $loaded->related[0]->name);

        // objects not written again keep their metadata
        $this->assertSame(1, $reopened->loadMetadata(self::KEYBOARD)?->getVersion());
    }

    public function testNewObjectsAndDeletesWork(): void
    {
        $category = $this->storage->load(self::PERIPHERALS);
        $headset = new Product('Headset', 79.0, 3);
        $headset->category = $category;
        $uuid = $this->storage->store($headset);

        $this->storage->delete(self::CABLE);

        $reopened = new ObjectStorage($this->dir);
        $this->assertSame('Peripherals', $reopened->load($uuid)?->category->title);
        $this->assertEqualsCanonicalizing([self::KEYBOARD, self::MOUSE, $uuid], iterator_to_array($reopened->list(Product::class), false));
        $this->assertNull($reopened->load(self::CABLE));
    }

    public function testRebuildingTheStubsFindsNothingToRepair(): void
    {
        $progress = $this->storage->rebuildStubs();

        $this->assertSame(0, $progress['created']);
        $this->assertSame(0, $progress['removed']);
        $this->assertSame(0, $progress['corrupted']);
        $this->assertCount(3, iterator_to_array($this->storage->list(Product::class), false));
    }

    public function testUniqueIndexesCanBeCreatedOnOldData(): void
    {
        $unique = new Manager($this->storage);
        $unique->create(new Definition('author-email', Author::class, 'email', caseInsensitive: true));
        $unique->attach();

        $this->assertSame(self::ADA, $unique->find('author-email', 'ADA@example.com')?->getUUID());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->storage->store(new Author('Another Ada', 'ada@EXAMPLE.com'));
    }

    public function testOqlQueriesOldData(): void
    {
        $result = (new Oql($this->storage))->query(
            'SELECT p.name AS name, c.title AS category, c.parent.title AS parent, a.name AS author
             FROM Product p JOIN Category c ON c = p.category LEFT JOIN Author a ON a = p.author
             ORDER BY p.name'
        );

        $this->assertSame([
            ['name' => 'Cable', 'category' => 'Hardware', 'parent' => null, 'author' => 'Ada'],
            ['name' => 'Keyboard', 'category' => 'Keyboards', 'parent' => 'Peripherals', 'author' => 'Ada'],
            ['name' => 'Mouse', 'category' => 'Peripherals', 'parent' => 'Hardware', 'author' => 'Grace'],
        ], $result->toArray());

        $count = (new Oql($this->storage))->query('SELECT COUNT(*) AS n, SUM(p.stock) AS stock FROM Product p WHERE p.active = true');
        $this->assertSame([['n' => 2, 'stock' => 262]], $count->toArray());
    }

    private function copyDirectory(string $from, string $to): void
    {
        $this->assertDirectoryExists($from);
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            $target = $to . DIRECTORY_SEPARATOR . substr($item->getPathname(), strlen($from) + 1);
            if ($item->isDir()) {
                @mkdir($target, 0777, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }
}
