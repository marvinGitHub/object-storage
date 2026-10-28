# Example: an audit log from events

A customer database must record who changed what and when: every change of a customer, with the old and the new values, and every deletion. Instead of adding logging code everywhere customers are changed, an event listener records the changes centrally.

**You will learn:** listening to events, `OBJECT_SAVED` with the previous and the new state, `AFTER_DELETE`, keeping the logic out of your domain code.

## The customer

```php
<?php

use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\Context\ObjectPersistenceContext;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\AwareTrait;

require __DIR__ . '/vendor/autoload.php';

final class Customer implements AwareInterface
{
    use AwareTrait;

    public function __construct(
        public string $name,
        public string $email,
        public string $plan = 'free',
    ) {}
}
```

## The audit log

```php
final class AuditLog
{
    /** @var list<string> */
    public array $entries = [];

    public function __construct(ObjectStorage $storage)
    {
        $events = $storage->getEventDispatcher();

        // dispatched after an object was written, and only if it actually changed
        $events->addListener(Events::OBJECT_SAVED, function (ObjectPersistenceContext $context): void {
            $this->recordChange($context->getUUID(), $context->getPreviousObject(), $context->getObject());
        });

        $events->addListener(Events::AFTER_DELETE, function (Context $context): void {
            $this->entries[] = sprintf('%s deleted', $context->getUUID());
        });
    }

    private function recordChange(string $uuid, ?object $previous, object $current): void
    {
        if (null === $previous) {
            $this->entries[] = sprintf('%s %s created', $current::class, $uuid);
            return;
        }

        $before = get_object_vars($previous);

        foreach (get_object_vars($current) as $property => $value) {
            if (($before[$property] ?? null) !== $value) {
                $this->entries[] = sprintf(
                    '%s %s: %s changed from %s to %s',
                    $current::class,
                    $uuid,
                    $property,
                    json_encode($before[$property] ?? null),
                    json_encode($value),
                );
            }
        }
    }
}
```

`getPreviousObject()` returns the object as it was stored before, read from disk, or `null` for a new object. The log compares it with the new state property by property. The listener receives every stored object, so the same log works for all classes.

## Using it

```php
$storage = new ObjectStorage(__DIR__ . '/var/customers');
$audit = new AuditLog($storage);

$customer = new Customer('Ada Lovelace', 'ada@example.com');
$storage->store($customer);

$customer->plan = 'pro';
$customer->email = 'ada@analytical.engine';
$storage->store($customer);

$storage->store($customer);  // unchanged: nothing is written, nothing is logged

$storage->delete($customer->getUUID());

echo implode(PHP_EOL, $audit->entries), PHP_EOL;
```

Output (UUIDs shortened):

```
Customer 3f2c… created
Customer 3f2c…: email changed from "ada@example.com" to "ada@analytical.engine"
Customer 3f2c…: plan changed from "free" to "pro"
3f2c… deleted
```

## In production

- **Persist the entries**, e.g. as JSON lines in a file, in a database table or a message queue, instead of keeping them in memory.
- **Mind the cost**: while a listener for `OBJECT_SAVED` is registered, every update reads the previous state of the object once.
- **Keep listeners fast and robust.** They run inside `store()` and `delete()`. Exceptions thrown by a listener are logged and don't interrupt the operation, so a failing audit log never blocks a change — check your logs.
- **Filter by class** if only some objects are audited: `if (!$object instanceof Customer) { return; }`.

See [Events](../events.md) for all events and their contexts.
