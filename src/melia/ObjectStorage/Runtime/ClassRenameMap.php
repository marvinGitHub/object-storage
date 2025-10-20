<?php

namespace melia\ObjectStorage\Runtime;

use melia\ObjectStorage\Exception\InvalidClassnameException;

class ClassRenameMap
{
    protected array $map = [];

    /**
     * Creates an alias by mapping an old class name to a new class name.
     *
     * @param string $oldClassName The original class name to be replaced.
     * @param string $newClassName The new class name to map to the old class name.
     * @return void
     * @throws InvalidClassNameException If either the old class name or the new class name is empty.
     */
    public function createAlias(string $oldClassName, string $newClassName): void
    {
        if (empty($oldClassName) || empty($newClassName)) {
            throw new InvalidClassNameException('Both old and new class names must be provided.');
        }

        $this->map[$oldClassName] = $newClassName;
    }

    /**
     * Retrieves the alias associated with the given class name.
     *
     * @param string $className The name of the class to retrieve the alias for.
     * @return string|null The alias for the class, or null if no alias is found.
     */
    public function getAlias(string $className): ?string
    {
        return $this->map[$className] ?? null;
    }
}