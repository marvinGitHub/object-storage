<?php

namespace melia\ObjectStorage\Event\Context;

use Throwable;

/**
 * A transaction interrupted by a crash, rolled back when the storage was opened (TRANSACTION_RECOVERED), or whose
 * rollback failed (TRANSACTION_RECOVERY_FAILED).
 */
class TransactionContext implements ContextInterface
{
    /**
     * @param string $transactionId The ID of the transaction.
     * @param string[] $uuids The objects the transaction had changed or meant to change.
     * @param Throwable|null $error Why the rollback failed, for TRANSACTION_RECOVERY_FAILED.
     */
    public function __construct(
        protected string     $transactionId,
        protected array      $uuids = [],
        protected ?Throwable $error = null,
    )
    {
    }

    public function getTransactionId(): string
    {
        return $this->transactionId;
    }

    /**
     * @return string[]
     */
    public function getUuids(): array
    {
        return $this->uuids;
    }

    public function getError(): ?Throwable
    {
        return $this->error;
    }
}
