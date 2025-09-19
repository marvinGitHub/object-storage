<?php

namespace melia\ObjectStorage\Migration;

use melia\ObjectStorage\Migration\Exception\AttributeRenameFailureException;
use melia\ObjectStorage\Reflection\Reflection;
use melia\ObjectStorage\Storage\StorageAwareTrait;
use ReflectionException;

class AttributeRename implements MigrationInterface
{
    use StorageAwareTrait;

    private string $previousName;
    private string $expectedName;

    public function __construct(string $previousName, string $expectedName)
    {
        $this->previousName = $previousName;
        $this->expectedName = $expectedName;
    }

    /**
     * @throws AttributeRenameFailureException|ReflectionException
     */
    public function apply(object $object): bool
    {
        $reflection = new Reflection($object);

        if ($reflection->initialized($this->expectedName)) {
            throw new AttributeRenameFailureException(sprintf('Attribute "%s" already exists', $this->expectedName));
        }

        if (false === $reflection->initialized($this->previousName)) {
            throw new AttributeRenameFailureException(sprintf('Attribute "%s" does not exist', $this->previousName));
        }

        $reflection->set($this->expectedName, $reflection->get($this->previousName));
        $reflection->unset($this->previousName);

        $this->getStorage()?->store($object);

        return true;
    }
}