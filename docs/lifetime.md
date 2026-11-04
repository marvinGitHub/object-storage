# Lifetime

Objects can expire. An expired object is treated as if it didn't exist: `load()` returns `null`. This is useful for caches, sessions, tokens or any data that is only valid for a while.

## Storing with a lifetime

Pass the lifetime in seconds as the third argument of `store()`:

```php
$uuid = $storage->store($session, ttl: 1800); // expires in 30 minutes
```

## Changing the lifetime

```php
$storage->setLifetime($uuid, 3600);                                 // one hour from now
$storage->setExpiration($uuid, new DateTimeImmutable('tomorrow'));  // at a point in time
$storage->setLifetime($uuid, null);                                 // never expires
```

Changing the lifetime only rewrites the metadata, not the object.

In the [viewer](maintenance.md#the-web-viewer), **Lifetime** on the page of a record sets it the same ways: never expiring, expiring in a number of seconds, minutes, hours or days, or at a point in time (in the time zone of the browser). The page shows when the record expires and the time left. Attached [search indexes](search.md#keeping-indexes-up-to-date) follow lifetime changes: a record whose lifetime is set to the past is removed from them, and added again when its lifetime is extended.

## Reading the lifetime

```php
$storage->getLifetime($uuid);   // remaining seconds as float, negative once expired, null if unlimited
$storage->getExpiration($uuid); // DateTimeInterface, or null if unlimited
$storage->expired($uuid);       // true once the lifetime is over
```

## Things to know

- **Storing again resets the lifetime.** `store($object)` without `ttl` stores the object without a lifetime, even if it had one. Pass the lifetime again, or use `setLifetime()` after storing.
- **Expired objects are not deleted automatically.** They stay on disk until they are deleted, so their lifetime can still be extended. Remove them regularly with the CLI:

  ```bash
  vendor/bin/object-storage maintenance:purge-expired --dir var/storage
  ```

- **Lazily loaded references to expired objects** throw a `DanglingReferenceException` when they are accessed.
- **Referenced objects get their own lifetime.** By default they don't expire with the object that references them. To let them inherit the lifetime, enable it on the strategy:

  ```php
  $storage->getStrategy()->enableLifetimeInheritance();
  ```

See [Caching API responses](examples/api-response-cache.md) for a complete example.
