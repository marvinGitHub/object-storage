<?php

namespace Tests\melia\ObjectStorage;

/**
 * Uses __serialize() / __unserialize() and references other objects, directly and in an array.
 */
class SerializableNode
{
    public ?object $next = null;
    public array $items = [];

    public function __construct(public string $name = '')
    {
    }

    public function __serialize(): array
    {
        return ['name' => $this->name, 'next' => $this->next, 'items' => $this->items];
    }

    public function __unserialize(array $data): void
    {
        $this->name = $data['name'];
        $this->next = $data['next'];
        $this->items = $data['items'];
    }
}
