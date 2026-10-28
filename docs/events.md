# Events

The storage dispatches an event for every operation. Listeners can build audit logs, keep search indexes up to date, collect metrics or invalidate other caches.

## Listening to events

```php
use melia\ObjectStorage\Event\Context\ObjectPersistenceContext;
use melia\ObjectStorage\Event\Events;

$storage->getEventDispatcher()->addListener(
    Events::AFTER_UPDATE,
    static function (ObjectPersistenceContext $context): void {
        printf("%s was updated\n", $context->getUUID());
    },
);
```

- A listener receives a context object with the details of the event (see the tables below), or `null` for events without details.
- Exceptions thrown by a listener are logged and don't interrupt the operation.
- Remove a listener with `removeListener()`, passing the same callable.
- Contexts are only created if a listener is registered, so unused events cost nothing.

The constants are defined in `melia\ObjectStorage\Event\Events`, the context classes in `melia\ObjectStorage\Event\Context`. Every context except `IOContext`, `ClassAliasCreationContext` and `LazyTypeNotSupportedContext` provides the UUID of the object via `getUUID()`.

## Storing

| Event | When | Context |
|---|---|---|
| `BEFORE_STORE` | before an object is stored; the UUID is `null` for objects without a UUID yet | `ObjectPersistenceContext` |
| `BEFORE_INITIAL_STORE` | before an object is stored for the first time | `ObjectPersistenceContext` |
| `BEFORE_UPDATE` | before an existing object is stored again | `ObjectPersistenceContext` |
| `OBJECT_SAVED` | after the object's data has been written; only if it changed | `ObjectPersistenceContext` with `getPreviousObject()` |
| `METADATA_SAVED` | after the object's metadata has been written | `Context` |
| `CLASSNAME_CHANGED` | an object was stored under a UUID that held an object of another class | `ClassnameChangeContext` |
| `STUB_CREATED` | the object was added to the class index | `StubContext` |
| `AFTER_UPDATE` | after an existing object has been stored | `ObjectPersistenceContext` |
| `AFTER_STORE` | after an object has been stored and unlocked | `Context` |

`ObjectPersistenceContext::getObject()` returns the stored object. `OBJECT_SAVED` is the event to react to actual changes: it is only dispatched when the stored data differs from what was stored before, and `getPreviousObject()` returns that previous state as a separate object (`null` for new objects). Reading the previous state costs a read, which only happens while a listener for `OBJECT_SAVED` is registered. See [An audit log from events](examples/audit-log-with-events.md).

## Loading

| Event | When | Context |
|---|---|---|
| `BEFORE_LOAD` | before an object is loaded | `Context` |
| `CACHE_HIT` | the object was served from the cache | `Context` |
| `OBJECT_EXPIRED` | the object's lifetime is over, `load()` returns `null` | `Context` |
| `AFTER_LOAD` | after an object has been loaded | `Context` |
| `LAZY_TYPE_NOT_SUPPORTED` | a referenced object had to be loaded immediately because the property type doesn't allow lazy loading | `LazyTypeNotSupportedContext` |
| `CLASS_ALIAS_CREATED` | the stored class doesn't exist; an alias was created so the object can still be loaded | `ClassAliasCreationContext` |

## Deleting

| Event | When | Context |
|---|---|---|
| `BEFORE_DELETE` | before an object is deleted | `Context` |
| `STUB_REMOVED` | the object was removed from the class index | `StubContext` |
| `AFTER_DELETE` | after an object has been deleted | `Context` |

## Lifetime

| Event | When | Context |
|---|---|---|
| `LIFETIME_CHANGED` | `setLifetime()` or `setExpiration()` changed the lifetime | `LifetimeContext` with `getExpiresAt()` (Unix timestamp or `null`) |

## Caching

| Event | When | Context |
|---|---|---|
| `CACHE_ENTRY_ADDED` | an object was put into the cache | `Context` |
| `CACHE_ENTRY_REMOVED` | an object was removed from the cache | `Context` |
| `CACHE_CLEARED` | the cache was cleared | none |

## Locking and safe mode

| Event | When | Context |
|---|---|---|
| `SHARED_LOCK_ACQUIRED` | a shared lock was acquired | `Context` |
| `EXCLUSIVE_LOCK_ACQUIRED` | an exclusive lock was acquired | `Context` |
| `LOCK_RELEASED` | a lock was released | `Context` |
| `SAFE_MODE_ENABLED` | the storage switched to safe mode, see [Durability](durability.md#safe-mode) | none |
| `SAFE_MODE_DISABLED` | safe mode was disabled | none |

## Failures

| Event | When | Context |
|---|---|---|
| `OBJECT_SAVING_FAILURE` | storing an object failed | `Context` |
| `OBJECT_WRITE_FAILED` | writing the object's files failed | `ObjectPersistenceContext` |
| `METADATA_WRITE_FAILED` | writing metadata failed | `Context` |
| `STUB_WRITE_FAILED` | adding the object to the class index failed | `StubContext` |
| `GRAPH_SERIALIZATION_FAILURE` | the object graph couldn't be serialized | `Context` |
| `OBJECT_LOADING_FAILURE` | loading an object failed | `Context` |
| `METADATA_LOADING_FAILURE` | an object's metadata couldn't be loaded | `Context` |
| `IO_READ_FAILURE` | a file couldn't be read | `IOContext` with `getPath()` |
| `JSON_DECODING_FAILURE` | a file didn't contain valid JSON | `IOContext` with `getPath()` |
| `OBJECT_DELETION_FAILURE` | deleting an object failed | `Context` |
| `CACHE_WRITE_FAILED` | putting an object into the cache failed | `Context` |
| `OBJECT_CORRUPTION_DETECTED` | rebuilding the class index found an object without class name; it was moved aside | `Context` |
