<?php

namespace melia\ObjectStorage\Query\Ast;

/**
 * ALTER CLASS Class DROP ATTRIBUTE name [, DROP ATTRIBUTE name] …
 * (also ALTER TABLE Class DROP [COLUMN] name, for SQL)
 *
 * @internal Not part of the public API; may change in any release.
 */
final readonly class DropAttributes
{
    /**
     * @param list<string> $attributes The properties removed from the objects.
     */
    public function __construct(public Source $source, public array $attributes)
    {
    }
}
