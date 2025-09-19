<?php

namespace melia\ObjectStorage\Migration;

use melia\ObjectStorage\Migration\Exception\Exception;
use melia\ObjectStorage\Reflection\Reflection;
use melia\ObjectStorage\Storage\StorageAwareTrait;

class Migration implements MigrationInterface
{
    use StorageAwareTrait;

    private $modifier;

    public function __construct(callable $modifier)
    {
        $this->modifier = $modifier;
    }

    /**
     * @throws Exception
     */
    public function apply(object $object): bool
    {
        $modified = ($this->modifier)(new Reflection($object));
        if ($modified === null) {
            $modified = $object;
        }

        if (false === is_object($modified)) {
            throw new Exception('Modifier must return an object');
        }

        $this->getStorage()?->store($modified);
        return true;
    }
}