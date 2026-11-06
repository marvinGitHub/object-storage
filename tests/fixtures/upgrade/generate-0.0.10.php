<?php
/**
 * Writes the storage in tests/fixtures/upgrade/storage-0.0.10 with the code of version 0.0.10, for UpgradeFrom0010Test.
 * Run it with the vendor directory of 0.0.10, not of this version:
 *
 *     git archive 0.0.10 | tar -x -C /tmp/v0010 && (cd /tmp/v0010 && composer install --no-dev)
 *     php tests/fixtures/upgrade/generate-0.0.10.php /tmp/v0010/vendor/autoload.php tests/fixtures/upgrade/storage-0.0.10
 *
 * The fixture classes are loaded from this repository (tests/melia/ObjectStorage/Upgrade/Fixture); the UUIDs are fixed,
 * the test refers to them.
 */

declare(strict_types=1);

use melia\ObjectStorage\ObjectStorage;
use Tests\melia\ObjectStorage\Upgrade\Fixture\Author;
use Tests\melia\ObjectStorage\Upgrade\Fixture\CacheEntry;
use Tests\melia\ObjectStorage\Upgrade\Fixture\Category;
use Tests\melia\ObjectStorage\Upgrade\Fixture\Note;
use Tests\melia\ObjectStorage\Upgrade\Fixture\Product;

[, $autoload, $target] = $argv + [null, null, null];
if (!$autoload || !$target || !is_file($autoload)) {
    fwrite(STDERR, "Usage: php generate-0.0.10.php <vendor/autoload.php of 0.0.10> <target directory>\n");
    exit(2);
}

require $autoload;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Tests\\melia\\ObjectStorage\\Upgrade\\Fixture\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/../../melia/ObjectStorage/Upgrade/Fixture/' . substr($class, strlen($prefix)) . '.php';
    }
});

if (file_exists($target)) {
    fwrite(STDERR, "The target exists, remove it first: $target\n");
    exit(2);
}
mkdir($target, 0777, true);

$uuid = static fn(int $n): string => sprintf('00000000-0000-4000-8000-%012d', $n);

$storage = new ObjectStorage($target);

// categories: a chain of three
$root = new Category('Hardware');
$root->setUUID($uuid(1));
$peripherals = new Category('Peripherals', $root);
$peripherals->setUUID($uuid(2));
$keyboards = new Category('Keyboards', $peripherals);
$keyboards->setUUID($uuid(3));

// authors
$ada = new Author('Ada', 'ada@example.com');
$ada->setUUID($uuid(10));
$grace = new Author('Grace', 'grace@example.com');
$grace->setUUID($uuid(11));

// products
$keyboard = new Product('Keyboard', 49.9, 12, true);
$keyboard->setUUID($uuid(20));
$keyboard->tags = ['input', 'usb'];
$keyboard->dimensions = ['width' => 44.5, 'depth' => 13, 'unit' => 'cm', 'colors' => ['black', 'white']];
$keyboard->category = $keyboards;
$keyboard->author = $ada;
$keyboard->description = "Mechanical, with \"quotes\", umlauts (äöü) and a\nnew line";

$mouse = new Product('Mouse', 19.5, 0, false);
$mouse->setUUID($uuid(21));
$mouse->tags = [];
$mouse->category = $peripherals;
$mouse->author = $grace;

$cable = new Product('Cable', 4.0, 250, true);
$cable->setUUID($uuid(22));
$cable->tags = ['usb'];
$cable->category = $root;
$cable->author = $ada;

$keyboard->related = [$mouse, $cable];
$mouse->related = [$keyboard];

// a cycle: the author references a product referencing the author
$ada->favorite = $keyboard;

foreach ([$root, $peripherals, $keyboards, $ada, $grace, $keyboard, $mouse, $cable] as $object) {
    $storage->store($object);
}

// stored by __serialize(), with a reference
$note = new Note('Remember the keyboard layout', $grace);
$note->setUUID($uuid(30));
$storage->store($note);

// expirations: one far in the future, one in the past
$valid = new CacheEntry('valid', 'still there');
$valid->setUUID($uuid(40));
$storage->store($valid);
$storage->setExpiration($uuid(40), new DateTimeImmutable('2100-01-01T00:00:00Z'));

$expired = new CacheEntry('expired', 'gone');
$expired->setUUID($uuid(41));
$storage->store($expired);
$storage->setExpiration($uuid(41), new DateTimeImmutable('2001-01-01T00:00:00Z'));

// stored and deleted
$deleted = new Product('Deleted', 1.0, 1, true);
$deleted->setUUID($uuid(50));
$storage->store($deleted);
$storage->delete($uuid(50));

echo "Written to $target\n";
