<?php
declare(strict_types=1);


require_once __DIR__ . '/vendor/autoload.php';

class User
{
    public $name;
}

$storage = new \melia\ObjectStorage\ObjectStorage('storage');

var_dump($storage->count());

$result = $storage->match(function ($object) {
   return isset($object->name) && $object->name === 'user_3000';
});

var_dump($result);