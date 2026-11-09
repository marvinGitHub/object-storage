<?php
/**
 * The storage the end-to-end tests of the viewer search: people with friends (references) and an index "people"
 * with fields of every type, one a path into the referenced friend. Usage: php tests/e2e/seed.php <directory>
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use melia\ObjectStorage\File\Directory;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\Manager;
use melia\ObjectStorage\Search\Loupe\LoupeEngine;

$directory = $argv[1] ?? throw new InvalidArgumentException('Usage: php seed.php <directory>');
if (is_dir($directory)) {
    Directory::remove($directory);
}
mkdir($directory, 0777, true);
$storage = new ObjectStorage($directory);

$people = [];
$person = static function (string $name, ?float $age, ?bool $active, ?array $tags) use (&$people): stdClass {
    $person = new stdClass();
    $person->name = $name;
    $person->age = $age;
    $person->active = $active;
    if (null !== $tags) {
        $person->tags = $tags;
    }
    return $people[$name] = $person;
};
$person('Anna', 30, true, ['red']);
$person('Homa', 25, false, ['green', 'blue']);
$person('Bob', -3, true, []);
$person('Carl', 40, null, ['red', 'blue']);
$person('Thomas', 50, true, null);
$person("O'Neill", 12.5, false, ['x, y']);
$people['Anna']->friend = $people['Homa'];
$people['Homa']->friend = $people['Bob'];
$people['Carl']->friend = $people['Thomas'];
$people['Thomas']->friend = $people['Anna'];
$people["O'Neill"]->friend = $people['Carl'];
foreach ($people as $object) {
    $storage->store($object);
}

$search = new Manager($storage, [new LoupeEngine()]);
$search->create(new Definition('people', stdClass::class, [
    new Field('name', 'name', filterable: true, sortable: true),
    new Field('age', 'age', FieldType::NUMBER, searchable: false, filterable: true, sortable: true),
    new Field('active', 'active', FieldType::BOOLEAN, searchable: false, filterable: true),
    new Field('tags', 'tags', FieldType::STRING_ARRAY, filterable: true),
    new Field('friendName', 'friend.name', filterable: true, sortable: true),
    new Field('friendAge', 'friend.age', FieldType::NUMBER, searchable: false, filterable: true),
]))->build();

// an appointment with dates (date-widgets.spec.js): one in Berlin, one in a list, one in a time zone of an offset
final class Appointment
{
    public function __construct(public string $title, public DateTimeImmutable $at, public array $reminders = [])
    {
    }
}
$storage->store(new Appointment(
    'Dentist',
    new DateTimeImmutable('2026-03-10 09:30:00', new DateTimeZone('Europe/Berlin')),
    [new DateTime('2026-03-09T18:00:00Z'), new DateTimeImmutable('2026-03-10T07:00:00+05:45')],
));

echo "seeded $directory\n";
