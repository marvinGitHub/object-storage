<?php
declare(strict_types=1);


require_once __DIR__ . '/vendor/autoload.php';

class User
{
    public $name;
}

$storage = new \melia\ObjectStorage\ObjectStorage('storage');

$i = 0;

do {
    $user = new User();
    $user->name = 'user_' . $i;
    $uuid = $storage->store($user);
    # $storage->clearCache();
    $i++;
} while ($i < 100000);