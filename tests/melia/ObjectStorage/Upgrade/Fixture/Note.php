<?php

namespace Tests\melia\ObjectStorage\Upgrade\Fixture;

use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\AwareTrait;

/**
 * Stored by its own __serialize(), with a referenced object among the values.
 *
 * Part of the storage written by 0.0.10 (tests/fixtures/upgrade): keep its name and properties, or regenerate it.
 */
class Note implements AwareInterface
{
    use AwareTrait;

    /** what __unserialize() received for the author, to check its type */
    public mixed $receivedAuthor = null;

    public function __construct(public string $text = '', public mixed $author = null)
    {
    }

    public function __serialize(): array
    {
        return ['uuid' => $this->uuid ?? null, 'text' => $this->text, 'author' => $this->author];
    }

    public function __unserialize(array $data): void
    {
        $this->uuid = $data['uuid'] ?? null;
        $this->text = $data['text'];
        $this->author = $data['author'];
        $this->receivedAuthor = $data['author'];
    }
}
