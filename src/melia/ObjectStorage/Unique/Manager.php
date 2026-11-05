<?php

namespace melia\ObjectStorage\Unique;

use Closure;
use melia\ObjectStorage\Constraint\ConstraintInterface;
use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\ObjectNotFoundException;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Unique\Exception\DuplicateValuesException;
use melia\ObjectStorage\Unique\Exception\InvalidUniqueIndexException;
use melia\ObjectStorage\Unique\Exception\UniqueConstraintViolationException;
use melia\ObjectStorage\Unique\Exception\UniqueIndexAlreadyExistsException;
use melia\ObjectStorage\Unique\Exception\UniqueIndexException;
use melia\ObjectStorage\Unique\Exception\UniqueIndexNotFoundException;
use melia\ObjectStorage\UUID\Helper;
use Throwable;

/**
 * Unique indexes of a storage: no two objects of a class may have the same values, like a UNIQUE constraint of a
 * database; and objects are found by their values.
 *
 *   $unique = new Manager($storage);
 *   $unique->create(new Definition('customer-email', Customer::class, 'email', caseInsensitive: true));
 *   $unique->attach();                                  // checked by every store() and writeRaw() from now on
 *
 *   $storage->store(new Customer('ada@example.com'));
 *   $storage->store(new Customer('ADA@example.com'));   // UniqueConstraintViolationException, nothing written
 *   $unique->find('customer-email', 'Ada@Example.com'); // the customer
 *
 * How it works: for each key (the values of an object at the paths of an index) a claim names the object that
 * has it, stored under a UUID derived from the index and the key in the internal storage `unique/internal`. While
 * an object is written, the claims of its keys stay locked, so of two processes writing the same key at the same
 * time only one succeeds. A claim whose object doesn't have the key anymore (e.g. left by a crash) is taken over.
 */
final class Manager implements ConstraintInterface
{
    /** The directory of the unique indexes in the storage. */
    public const string DIRECTORY = 'unique';
    /** The internal storage keeping definitions and claims. */
    public const string INTERNAL_DIRECTORY = 'internal';

    private ?ObjectStorage $internal = null;
    /** @var array<string, Definition>|null */
    private ?array $definitions = null;
    private ?Closure $releaseListener = null;

    public function __construct(private readonly ObjectStorage $storage)
    {
    }

    public function getStorage(): ObjectStorage
    {
        return $this->storage;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Managing indexes
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Creates a unique index and claims the keys of the objects stored before. If some of them share values, the
     * index isn't created.
     *
     * @throws UniqueIndexAlreadyExistsException If an index of the name exists.
     * @throws DuplicateValuesException If objects stored before share values; nothing is created.
     * @throws InvalidUniqueIndexException If there are objects of the class, but none has a single value at the
     *         paths (a wrong path, or a list); nothing is created.
     * @throws UniqueIndexException
     */
    public function create(Definition $definition): void
    {
        $internal = $this->getInternalStorage();
        $uuid = self::definitionUuid($definition->name);
        $lockAdapter = $internal->getLockAdapter();
        $lock = self::lockUuid('definition', $definition->name);

        $lockAdapter->acquireExclusiveLock($lock);
        try {
            if ($internal->exists($uuid)) {
                throw new UniqueIndexAlreadyExistsException(sprintf('A unique index "%s" exists already.', $definition->name));
            }
            // the definition first: objects written meanwhile by this process are checked against it
            $internal->store($definition, $uuid);
            $this->definitions = null;
        } finally {
            if ($lockAdapter->isLockedByThisProcess($lock)) {
                $lockAdapter->releaseLock($lock);
            }
        }

        try {
            $this->writeClaims($definition, $this->collectKeys($definition));
        } catch (Throwable $e) {
            $this->drop($definition->name);
            throw $e instanceof UniqueIndexException ? $e : new UniqueIndexException(message: sprintf('Unable to create unique index "%s".', $definition->name), previous: $e);
        }
    }

    /**
     * Claims the keys of all objects again, e.g. after objects were written without the index being attached.
     *
     * @return int The number of keys claimed.
     * @throws DuplicateValuesException If objects share values; the claims are left as they were.
     * @throws UniqueIndexNotFoundException
     */
    public function rebuild(string $name): int
    {
        $definition = $this->get($name);
        $keys = $this->collectKeys($definition); // before anything is removed: duplicates leave the claims as they are
        $this->removeClaims($name);
        $this->writeClaims($definition, $keys);
        return count($keys);
    }

    /**
     * Removes a unique index with its claims; the objects are not affected.
     *
     * @return bool False if there was no index of the name.
     */
    public function drop(string $name): bool
    {
        if (!$this->has($name)) {
            return false;
        }
        $this->getInternalStorage()->delete(self::definitionUuid($name));
        $this->definitions = null;
        $this->removeClaims($name);
        return true;
    }

    /**
     * @return array<string, Definition> All unique indexes, by name.
     */
    public function list(): array
    {
        if (null === $this->definitions) {
            $this->definitions = [];
            // a storage without unique indexes gets no directory for them by being read or written
            if (null === $this->internal && !is_dir($this->internalDirectory())) {
                return $this->definitions;
            }
            $internal = $this->getInternalStorage();
            foreach ($internal->list(Definition::class) as $uuid) {
                $definition = $internal->load($uuid);
                if ($definition instanceof Definition) {
                    $this->definitions[$definition->name] = $definition;
                }
            }
            ksort($this->definitions);
        }
        return $this->definitions;
    }

    /**
     * Reads the definitions again, e.g. in a long-running process after another process created or dropped an
     * index.
     */
    public function refresh(): void
    {
        $this->definitions = null;
    }

    public function has(string $name): bool
    {
        return isset($this->list()[$name]);
    }

    /**
     * @throws UniqueIndexNotFoundException
     */
    public function get(string $name): Definition
    {
        return $this->list()[$name] ?? throw new UniqueIndexNotFoundException(sprintf('There is no unique index "%s".', $name));
    }

    /**
     * @return array<string, Definition> The unique indexes of a class, by name.
     */
    public function getIndexesForClass(string $className): array
    {
        return array_filter($this->list(), static fn(Definition $definition): bool => $definition->className === $className);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Finding objects by their values
    // ---------------------------------------------------------------------------------------------------------

    /**
     * The UUID of the object having the values, null if none: a lookup by key, without a search.
     *
     * @param mixed ...$values The values, in the order of the paths of the index.
     * @throws UniqueIndexNotFoundException
     */
    public function findOwner(string $name, mixed ...$values): ?string
    {
        $definition = $this->get($name);
        $key = $definition->keyOfValues($values);
        if (null === $key) {
            return null;
        }

        $claim = $this->getInternalStorage()->load(self::claimUuid($name, $key));
        if (!$claim instanceof Claim) {
            return null;
        }
        // a claim left by a crash or a write without the index attached isn't trusted
        return $this->holds($claim->owner, $definition, $key) ? $claim->owner : null;
    }

    /**
     * The object having the values, null if none.
     *
     * @param mixed ...$values The values, in the order of the paths of the index.
     * @throws UniqueIndexNotFoundException
     */
    public function find(string $name, mixed ...$values): ?object
    {
        $owner = $this->findOwner($name, ...$values);
        return null === $owner ? null : $this->storage->load($owner);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Checking writes
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Checks every object written to the storage from now on, and releases the keys of deleted objects.
     */
    public function attach(): void
    {
        $this->storage->addConstraint($this);
        if (null === $this->releaseListener) {
            $this->releaseListener = function (?Context $context): void {
                if (null !== $context) {
                    $this->release($context->getUUID());
                }
            };
            $this->storage->getEventDispatcher()?->addListener(Events::AFTER_DELETE, $this->releaseListener);
        }
    }

    public function detach(): void
    {
        $this->storage->removeConstraint($this);
        if (null !== $this->releaseListener) {
            $this->storage->getEventDispatcher()?->removeListener(Events::AFTER_DELETE, $this->releaseListener);
            $this->releaseListener = null;
        }
    }

    /**
     * Called by the storage before an object is written, under its lock: claims its keys, or fails if another
     * object has them. The claims stay locked until the object is written.
     */
    public function check(string $uuid, string $className, array $data, string $reservedReferenceName): ?Closure
    {
        $changes = [];
        foreach ($this->list() as $definition) {
            $new = $definition->className === $className ? $definition->keyOf($data, $reservedReferenceName) : null;
            $old = $this->ownedKey($definition->name, $uuid);
            if ($new['key'] ?? null) {
                if ($new['key'] === $old) {
                    continue; // unchanged
                }
            } elseif (null === $old) {
                continue; // no key before, none now
            }
            $changes[] = ['definition' => $definition, 'new' => $new, 'old' => $old];
        }
        if ([] === $changes) {
            return null;
        }

        // the locks in a fixed order, so processes claiming several keys can't wait for each other
        usort($changes, static fn(array $a, array $b): int => strcmp(
            null === $a['new'] ? '' : self::claimUuid($a['definition']->name, $a['new']['key']),
            null === $b['new'] ? '' : self::claimUuid($b['definition']->name, $b['new']['key'])
        ));

        $internal = $this->getInternalStorage();
        $lockAdapter = $internal->getLockAdapter();
        $locks = [];
        $created = [];

        try {
            foreach ($changes as ['definition' => $definition, 'new' => $new]) {
                if (null === $new) {
                    continue;
                }
                $claimUuid = self::claimUuid($definition->name, $new['key']);
                $lock = self::lockUuid('claim', $definition->name . "\0" . $new['key']);
                $lockAdapter->acquireExclusiveLock($lock);
                $locks[] = $lock;

                $claim = $internal->load($claimUuid);
                if ($claim instanceof Claim && $claim->owner === $uuid) {
                    continue; // the object has the key already (e.g. its owned key was lost): nothing to undo later
                }
                if ($claim instanceof Claim && $this->holds($claim->owner, $definition, $new['key'])) {
                    throw new UniqueConstraintViolationException($definition->name, $new['values'], $uuid, $claim->owner);
                }

                // a new key, or a claim of an object that doesn't have the key anymore
                $internal->store(new Claim($definition->name, $new['key'], $new['values'], $uuid), $claimUuid);
                $created[] = $claimUuid;
            }
        } catch (Throwable $e) {
            $this->undo($created, $locks);
            throw $e;
        }

        return function (bool $written) use ($changes, $created, $locks, $uuid): void {
            if (!$written) {
                $this->undo($created, $locks);
                return;
            }
            try {
                $internal = $this->getInternalStorage();
                foreach ($changes as ['definition' => $definition, 'new' => $new, 'old' => $old]) {
                    if (null !== $old) {
                        $this->releaseClaim($definition->name, $old, $uuid);
                    }
                    $ownedUuid = self::ownedKeyUuid($definition->name, $uuid);
                    if (null === $new) {
                        if ($internal->exists($ownedUuid)) {
                            $internal->delete($ownedUuid);
                        }
                    } else {
                        $internal->store(new OwnedKey($definition->name, $uuid, $new['key']), $ownedUuid);
                    }
                }
            } finally {
                $this->unlock($locks);
            }
        };
    }

    /**
     * Releases the keys of an object, e.g. after it was deleted (attach() does this).
     */
    public function release(string $uuid): void
    {
        $definitions = $this->list();
        if ([] === $definitions) {
            return;
        }
        $internal = $this->getInternalStorage();
        foreach ($definitions as $definition) {
            $key = $this->ownedKey($definition->name, $uuid);
            if (null === $key) {
                continue;
            }
            $this->releaseClaim($definition->name, $key, $uuid);
            $internal->delete(self::ownedKeyUuid($definition->name, $uuid));
        }
    }

    // ---------------------------------------------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------------------------------------------

    /**
     * The storage keeping definitions and claims: the same locks as the storage, no cache.
     */
    public function getInternalStorage(): ObjectStorage
    {
        if (null === $this->internal) {
            $internal = new ObjectStorage(
                $this->internalDirectory(),
                logger: $this->storage->getLogger(),
                configuration: $this->storage->getConfiguration()?->forInternalStorage()
            );
            $internal->setCache(null);
            $internal->setMetadataCache(null);
            $this->internal = $internal;
        }
        return $this->internal;
    }

    private function internalDirectory(): string
    {
        return $this->storage->getStorageDir() . DIRECTORY_SEPARATOR . self::DIRECTORY . DIRECTORY_SEPARATOR . self::INTERNAL_DIRECTORY;
    }

    public static function definitionUuid(string $name): string
    {
        return Helper::createFixedUuidV4('melia/object-storage:unique:definition:' . $name);
    }

    public static function claimUuid(string $name, string $key): string
    {
        return Helper::createFixedUuidV4('melia/object-storage:unique:claim:' . $name . "\0" . $key, 'sha256');
    }

    public static function ownedKeyUuid(string $name, string $owner): string
    {
        return Helper::createFixedUuidV4('melia/object-storage:unique:owned:' . $name . ':' . $owner);
    }

    private static function lockUuid(string $kind, string $subject): string
    {
        return Helper::createFixedUuidV4('melia/object-storage:unique:lock:' . $kind . ':' . $subject, 'sha256');
    }

    /**
     * Whether an object has a key: it exists, is of the class and has the values, as stored.
     */
    private function holds(string $owner, Definition $definition, string $key): bool
    {
        try {
            $object = $this->storage->readRaw($owner);
        } catch (ObjectNotFoundException) {
            return false;
        }
        if ($object->isExpired() || $object->metadata->getClassName() !== $definition->className) {
            return false;
        }
        $data = json_decode($object->data, true);
        return is_array($data) && ($definition->keyOf($data, $object->metadata->getReservedReferenceName())['key'] ?? null) === $key;
    }

    private function ownedKey(string $name, string $owner): ?string
    {
        $owned = $this->getInternalStorage()->load(self::ownedKeyUuid($name, $owner));
        return $owned instanceof OwnedKey ? $owned->key : null;
    }

    /**
     * Removes the claim of a key if the object has it.
     */
    private function releaseClaim(string $name, string $key, string $owner): void
    {
        $internal = $this->getInternalStorage();
        $lockAdapter = $internal->getLockAdapter();
        $lock = self::lockUuid('claim', $name . "\0" . $key);
        $heldBefore = $lockAdapter->isLockedByThisProcess($lock);

        if (!$heldBefore) {
            $lockAdapter->acquireExclusiveLock($lock);
        }
        try {
            $claimUuid = self::claimUuid($name, $key);
            $claim = $internal->load($claimUuid);
            if ($claim instanceof Claim && $claim->owner === $owner) {
                $internal->delete($claimUuid);
            }
        } finally {
            if (!$heldBefore && $lockAdapter->isLockedByThisProcess($lock)) {
                $lockAdapter->releaseLock($lock);
            }
        }
    }

    /**
     * The keys of the objects stored before.
     *
     * @return array<string, array{owner: string, values: array<string, mixed>}> key => its object and values
     * @throws DuplicateValuesException If objects share values.
     */
    private function collectKeys(Definition $definition): array
    {
        $owners = [];
        $duplicates = [];

        $objects = 0;
        foreach ($this->storage->list($definition->className) as $uuid) {
            try {
                $object = $this->storage->readRaw((string)$uuid);
            } catch (ObjectNotFoundException) {
                continue;
            }
            if ($object->isExpired()) {
                continue;
            }
            $objects++;
            $data = json_decode($object->data, true);
            $key = is_array($data) ? $definition->keyOf($data, $object->metadata->getReservedReferenceName()) : null;
            if (null === $key) {
                continue;
            }
            if (isset($owners[$key['key']])) {
                $duplicates[$key['key']] ??= [$owners[$key['key']]['owner']];
                $duplicates[$key['key']][] = (string)$uuid;
                continue;
            }
            $owners[$key['key']] = ['owner' => (string)$uuid, 'values' => $key['values']];
        }

        if ([] !== $duplicates) {
            throw new DuplicateValuesException($definition->name, $duplicates);
        }
        // objects, but none with a single value at the paths: most likely a wrong path, or one leading to a list
        if ($objects > 0 && [] === $owners) {
            throw new InvalidUniqueIndexException(sprintf(
                'Unique index "%s": none of the %d objects of %s has a single value at %s; check the paths (lists can\'t be unique).',
                $definition->name,
                $objects,
                $definition->className,
                implode(', ', $definition->paths)
            ));
        }

        return $owners;
    }

    /**
     * @param array<string, array{owner: string, values: array<string, mixed>}> $keys As collected.
     */
    private function writeClaims(Definition $definition, array $keys): void
    {
        $internal = $this->getInternalStorage();
        foreach ($keys as $key => ['owner' => $owner, 'values' => $values]) {
            $internal->store(new Claim($definition->name, (string)$key, $values, $owner), self::claimUuid($definition->name, (string)$key));
            $internal->store(new OwnedKey($definition->name, $owner, (string)$key), self::ownedKeyUuid($definition->name, $owner));
        }
    }

    private function removeClaims(string $name): void
    {
        $internal = $this->getInternalStorage();
        foreach ([Claim::class, OwnedKey::class] as $class) {
            foreach (iterator_to_array($internal->list($class), false) as $uuid) {
                $record = $internal->load((string)$uuid);
                if (($record instanceof Claim || $record instanceof OwnedKey) && $record->index === $name) {
                    $internal->delete((string)$uuid);
                }
            }
        }
    }

    /**
     * @param string[] $created Claims written by a check
     * @param string[] $locks Locks taken by it
     */
    private function undo(array $created, array $locks): void
    {
        try {
            foreach ($created as $claimUuid) {
                // claims of keys nobody had, or stale ones: removing them leaves nothing wrong behind
                if ($this->getInternalStorage()->exists($claimUuid)) {
                    $this->getInternalStorage()->delete($claimUuid);
                }
            }
        } finally {
            $this->unlock($locks);
        }
    }

    /**
     * @param string[] $locks
     */
    private function unlock(array $locks): void
    {
        $lockAdapter = $this->getInternalStorage()->getLockAdapter();
        foreach ($locks as $lock) {
            if ($lockAdapter->isLockedByThisProcess($lock)) {
                $lockAdapter->releaseLock($lock);
            }
        }
    }
}
