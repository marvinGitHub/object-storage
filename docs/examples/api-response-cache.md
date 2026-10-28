# Example: caching API responses

An application calls a slow external API. Responses are cached for a few minutes, so repeated requests are answered from the cache, and the cache survives restarts.

**You will learn:** lifetime (TTL), choosing UUIDs yourself, a fast setup for data that can be recreated, purging expired objects.

## The cached response

```php
<?php

use melia\ObjectStorage\ObjectStorage;

require __DIR__ . '/vendor/autoload.php';

final class CachedResponse
{
    public function __construct(
        public string $url,
        public int $status,
        public string $body,
        public int $fetchedAt,
    ) {}
}
```

## A storage tuned for caching

```php
$cache = new ObjectStorage(__DIR__ . '/var/http-cache');

// cached responses can always be fetched again: no need to flush every write to disk
$cache->getWriter()->setSyncEnabled(false);
```

Without `fsync`, writes are much faster and still atomic; only a power loss may lose the most recent entries, which is fine for a cache. See [Durability](../durability.md#fsync).

## One UUID per URL

To find the cached response of a URL without searching, derive the UUID from the URL. A UUID must have the format of a UUID version 4, so the hash is shaped accordingly:

```php
function cacheKey(string $url): string
{
    $hash = md5($url);

    return sprintf(
        '%s-%s-4%s-%x%s-%s',
        substr($hash, 0, 8),
        substr($hash, 8, 4),
        substr($hash, 13, 3),
        8 + (hexdec($hash[16]) & 3), // variant bits: 8, 9, a or b
        substr($hash, 17, 3),
        substr($hash, 20, 12),
    );
}
```

## Fetching through the cache

```php
function fetchCached(ObjectStorage $cache, string $url, callable $fetch, int $ttl = 300): CachedResponse
{
    $key = cacheKey($url);

    // load() returns null if nothing is cached yet or the lifetime is over
    if (null !== ($cached = $cache->load($key))) {
        return $cached;
    }

    /** @var CachedResponse $response */
    $response = $fetch($url);
    $cache->store($response, $key, ttl: $ttl);

    return $response;
}
```

Try it with a fake API that counts its calls:

```php
$calls = 0;
$api = static function (string $url) use (&$calls): CachedResponse {
    $calls++;
    return new CachedResponse($url, 200, '{"temperature": 21}', time());
};

$url = 'https://api.example.com/weather?city=Berlin';

fetchCached($cache, $url, $api);
fetchCached($cache, $url, $api);
fetchCached($cache, $url, $api);

echo "API calls: $calls", PHP_EOL; // API calls: 1
```

And once the lifetime is over, the API is called again:

```php
fetchCached($cache, 'https://api.example.com/slow', $api, ttl: 1);
sleep(2);
fetchCached($cache, 'https://api.example.com/slow', $api, ttl: 1);

echo "API calls: $calls", PHP_EOL; // API calls: 3
```

Storing the fresh response under the same UUID replaces the expired one, with a new lifetime.

## Inspecting and refreshing entries

```php
$key = cacheKey($url);

$cache->getLifetime($key);         // remaining seconds
$cache->setLifetime($key, 3600);   // keep this one longer
$cache->delete($key);              // force a refresh on the next call
```

## Cleaning up

Expired entries stay on disk until they are deleted. Run the purge regularly, e.g. from cron:

```bash
vendor/bin/object-storage maintenance:purge-expired --dir var/http-cache
```

## Several servers

When several servers share the cache directory (e.g. on a network file system), file locks don't coordinate them. Use a Redis lock store instead, see [Concurrency](../concurrency.md#lock-backends):

```php
use melia\ObjectStorage\Locking\Backends\SymfonyLockAdapter;
use Symfony\Component\Lock\Store\RedisStore;

$redis = new Redis();
$redis->connect('redis', 6379);

$cache = new ObjectStorage(
    __DIR__ . '/var/http-cache',
    lockAdapter: new SymfonyLockAdapter(new RedisStore($redis), prefix: 'http-cache.'),
);
```
