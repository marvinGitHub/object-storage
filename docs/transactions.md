# Transactions

A transaction stores and deletes several objects together: either all changes are written, or none — also if the process crashes in the middle of writing them.

```php
use melia\ObjectStorage\Transaction;

$transaction = (new Transaction($storage))->begin();

$transaction->store($order);
$transaction->store($product, $product->getUUID());
$transaction->delete($reservationUuid);

$transaction->commit();   // writes all changes, or none
// $transaction->rollback(); // discards them
```

## How it works

- **Changes are collected.** `store()` and `delete()` only record the change; `commit()` writes them in the order they were made. `$transaction->load($uuid)` returns an object as stored (or deleted, `null`) in the transaction, otherwise as stored.
- **Objects are locked** exclusively from their first change in the transaction until the commit or rollback (waiting up to the timeout of the constructor, 30 seconds by default; `TransactionLockException` otherwise). Other processes wait for them meanwhile.
- **The state before is recorded.** Before the first change of an object, its state as stored — read from the files under the lock, not from the cache — is written to the journal of the transaction in `<storage dir>/transactions/<transaction id>/`. Objects that don't exist are recorded as missing.
- **Commit:** the changes are written, then a commit entry is appended to the journal, and the journal is removed.
- **A failed commit is rolled back:** if a change can't be written, the objects are restored to their recorded state — data, revision, checksum and expiration exactly as they were, without the hooks, policies and revision increment of `store()` — and objects created by the transaction are deleted. `commit()` throws a `TransactionCommitException` with the cause.
- **`rollback()` before the commit** discards the changes; nothing has been written.
- A transaction left active is rolled back by its destructor.

## The storage instance is the connection

While a transaction is active, it is the active transaction of its storage instance (`$storage->getActiveTransaction()`): **every change of object files through that instance** is recorded before it is made and rolled back with the rest — the objects of `$transaction->store()` and `delete()`, but also `$storage->store()`, `delete()`, `setLifetime()`, `touch()` and `writeRaw()` called meanwhile, [OQL statements](query.md#transactions-and-scripts), objects written along by the [child persistence policy](configuration.md#child-persistence-when-referenced-objects-are-written) and deleted along by the [child deletion policy](configuration.md#child-deletion-whether-referenced-objects-are-deleted-along). Each of these objects stays locked until the end of the transaction. Only objects the transaction changed are restored; changes of other processes are never touched.

- **One transaction per storage instance:** `begin()` throws a `TransactionAlreadyActiveException` while another one is active on the instance, as a change couldn't be told apart. Parallel transactions in one process use several `ObjectStorage` instances on the directory: each has its own locks, so they wait for each other like two processes (an object changed by one is locked for the other until it ends).
- OQL has transactions with `START TRANSACTION`, `COMMIT`, `ROLLBACK`, and makes every writing statement atomic, see [Transactions and scripts](query.md#transactions-and-scripts). Its statements write at once (the journal records the states before), so a connection sees its own changes.

## Crashes

If the process dies during `commit()` — killed, out of memory, power loss — some changes are written and the journal remains. The next time a storage is opened on the directory, it rolls the transaction back, as a failed commit would have been. A journal ending with the commit entry was committed completely and is just removed.

- **Running transactions are left alone.** A transaction holds a lock of its own while it runs; a storage opened meanwhile, e.g. by another request, skips its journal. With the default file locks, a crashed process releases its locks; with Redis, Memcached or a database, they expire after their TTL.
- **Opening a storage** without interrupted transactions costs one directory lookup.
- **A failing rollback** (e.g. a damaged journal, or an object that can't be written) enables [safe mode](durability.md#safe-mode) and keeps the journal; the rollback is tried again when the storage is opened next, after safe mode has been disabled. The events `TRANSACTION_RECOVERED` and `TRANSACTION_RECOVERY_FAILED` report rollbacks of interrupted transactions, see [Events](events.md#transactions).
- With [fsync](durability.md#fsync) disabled, the journal is not flushed either: a crashed process is rolled back, a power loss may lose the journal of the last moments.

## Limits

- **Keep transactions short:** the objects changed stay locked until the end; other processes wait for them, at most the timeout of their locks, then fail. A transaction can't outlive its process (or the request of a web application).
- **Isolation:** objects are locked from their first change, not from being read. Load an object `exclusive: true` before changing it in a transaction if another process must not change it in between (see [Concurrency](concurrency.md)). `Transaction::store()` stores a copy of the object, which is not checked by optimistic locking.
- **Unique indexes:** a value released by the transaction may be claimed by another process before a rollback restores it; the rollback of that object then fails with safe mode, see above.
- **Several hosts:** the journal of a transaction is written by one process only. Crash recovery needs a lock backend shared by all hosts (Redis, a database), so a running transaction on another host isn't taken for crashed.
- A committed transaction can't be rolled back.

## The journal

The journal is a `melia\ObjectStorage\Journal\Journal`: an append-only log of entries numbered without gaps, written crash-safe (an interrupted append damages at most the last entry, which is ignored and cut off), with a checksum per entry. It can be used on its own, see [Internals](internals.md#journal).
