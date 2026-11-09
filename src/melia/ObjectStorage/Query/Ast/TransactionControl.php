<?php

namespace melia\ObjectStorage\Query\Ast;

/**
 * START TRANSACTION | BEGIN [WORK], COMMIT [WORK], ROLLBACK [WORK]
 *
 * @internal Not part of the public API; may change in any release.
 */
final readonly class TransactionControl
{
    public const string BEGIN = 'START TRANSACTION';
    public const string COMMIT = 'COMMIT';
    public const string ROLLBACK = 'ROLLBACK';

    /**
     * @param string $action One of the constants.
     */
    public function __construct(public string $action)
    {
    }
}
