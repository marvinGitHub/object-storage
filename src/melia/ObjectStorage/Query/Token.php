<?php

namespace melia\ObjectStorage\Query;

final readonly class Token
{
    /**
     * @param string $type One of the constants of Lexer.
     * @param string $value Keywords in capitals, strings without quotes, parameters by name or position.
     * @param int $position The offset in the query.
     */
    public function __construct(
        public string $type,
        public string $value,
        public int    $position,
    )
    {
    }

    public function is(string $type, ?string $value = null): bool
    {
        return $this->type === $type && (null === $value || $this->value === $value);
    }
}
