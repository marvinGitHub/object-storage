<?php

namespace melia\ObjectStorage\Query\Ast;

/** [INNER] JOIN or LEFT [OUTER] JOIN of a source ON a condition */
final readonly class Join
{
    public const string INNER = 'INNER';
    public const string LEFT = 'LEFT';

    public function __construct(public string $type, public Source $source, public Expr $on)
    {
    }
}
