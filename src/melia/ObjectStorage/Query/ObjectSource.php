<?php

namespace melia\ObjectStorage\Query;

use Closure;
use Generator;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Query\Ast\Expr;
use melia\ObjectStorage\Query\Ast\Source;
use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Reflection\Reflection;
use melia\ObjectStorage\Search\Index\ManagerInterface;
use melia\ObjectStorage\Transfer\RawObject;
use melia\ObjectStorage\Unique\Manager as UniqueManager;
use Throwable;

/**
 * The objects of a statement, as stored (their data, not loaded objects: the classes aren't needed): the objects of
 * a source, found by an index where one fits the condition (see Access) or all of the class; the referenced objects
 * paths lead to, a bounded number kept while the statement runs.
 *
 * Counts the objects read and tells how the objects of each source were found (Statistics); stops the statement if
 * its interrupt says so.
 *
 * @internal part of Executor
 */
final class ObjectSource
{
    /** the most referenced objects kept while a statement runs, for paths into them */
    public const int MAX_REFERENCED = 10000;

    /** @var array<string, ?array{uuid: string, data: array, reserved: string}> referenced objects read, by UUID */
    private array $referenced = [];

    /** @var list<string>|null */
    private ?array $classNames = null;

    private readonly Access $access;

    /** the objects read from the storage (Statistics::$objectsRead) */
    private int $objectsRead = 0;

    /** @var list<array{source: string, class: string, access: string}> how the objects of each source were found */
    private array $accessed = [];

    /**
     * @param \Closure(): void|null $interrupt Throws a QueryInterruptedException to stop the statement.
     */
    public function __construct(
        private readonly ObjectStorage $storage,
        ?ManagerInterface              $search = null,
        ?UniqueManager                 $unique = null,
        bool                           $useIndexes = true,
        private readonly ?Closure      $interrupt = null,
    )
    {
        $this->access = new Access($storage, $useIndexes ? $search : null, $useIndexes ? $unique : null, $useIndexes);
    }

    /**
     * Stops the statement if its interrupt says so (throws a QueryInterruptedException); called for every object
     * read, joined pair and object written.
     */
    public function checkpoint(): void
    {
        $this->interrupt?->__invoke();
    }

    /**
     * The objects read from the storage so far.
     */
    public function objectsRead(): int
    {
        return $this->objectsRead;
    }

    /**
     * How the objects of each source were found so far, in the order they were read.
     *
     * @return list<array{source: string, class: string, access: string}>
     */
    public function accessed(): array
    {
        return $this->accessed;
    }

    /**
     * How the objects of a source would be found for a condition, with the reasons (for EXPLAIN).
     */
    public function choose(Source $source, ?Expr $where): AccessChoice
    {
        return $this->access->choose($source, $this->classOf($source), $where, true);
    }

    /**
     * The objects of a source, one after another: those an index finds for the condition (checked by the caller
     * anyway), or all of the class.
     *
     * @return Generator<array{uuid: string, class: string, data: array, reserved: string, dateTime: string, created: ?float, modified: ?float}>
     */
    public function objectsOf(Source $source, ?Expr $where): Generator
    {
        $class = $this->classOf($source);
        $access = $this->access->choose($source, $class, $where);
        $this->accessed[] = ['source' => $source->alias, 'class' => $class, 'access' => $access->kind];
        $uuids = $access->uuids ?? $this->storage->list($class);
        foreach ($uuids as $uuid) {
            $this->checkpoint();
            $object = $this->read((string)$uuid);
            // an index may name an object of another class (a unique index of a parent class, a UUID), and so may a
            // stale stub (e.g. left by an interrupted change of the class): a DELETE must not reach other classes
            if (null !== $object && $object['class'] === $class) {
                yield $object;
            }
        }
    }

    /**
     * The referenced objects for paths (see DocumentBuilder::extract()): [data, reserved reference name] of an
     * object by its UUID, null if it doesn't exist or has expired. Kept while the statement runs, the older half
     * dropped when there are MAX_REFERENCED.
     *
     * @return Closure(string): ?array{0: array, 1: string}
     */
    public function follow(): Closure
    {
        return function (string $uuid): ?array {
            if (!array_key_exists($uuid, $this->referenced)) {
                if (count($this->referenced) >= self::MAX_REFERENCED) {
                    // the older half goes
                    $this->referenced = array_slice($this->referenced, intdiv(self::MAX_REFERENCED, 2), null, true);
                }
                $this->referenced[$uuid] = $this->read($uuid, strict: true);
            }
            $object = $this->referenced[$uuid];
            return null === $object ? null : [$object['data'], $object['reserved']];
        };
    }

    /**
     * Drops the referenced objects kept, e.g. before a statement changes objects.
     */
    public function forgetReferenced(): void
    {
        $this->referenced = [];
    }

    /**
     * The class of a source: its full name, or its short name if one stored class has it.
     *
     * @throws QueryException If the name is ambiguous or no such class is known.
     */
    public function classOf(Source $source): string
    {
        $this->classNames ??= $this->storage->getClassNames();
        $name = $source->class;
        if (in_array($name, $this->classNames, true)) {
            return $name;
        }
        $matches = array_values(array_filter($this->classNames, static fn(string $class): bool => 0 === strcasecmp($class, $name)
            || 0 === strcasecmp(Reflection::shortName($class), $name)));
        if (1 === count($matches)) {
            return $matches[0];
        }
        if (count($matches) > 1) {
            throw new QueryException(sprintf('"%s" is ambiguous: %s; write the full name in backticks, e.g. `%s`', $name, implode(', ', $matches), $matches[0]), $source->position);
        }
        if (class_exists($name)) {
            return $name; // no objects of it are stored
        }
        throw new QueryException(sprintf('Unknown class "%s": no objects of it are stored', $name), $source->position);
    }

    /**
     * An object of a row: its UUID, data, reserved reference name and reserved key of dates; null if expired or not
     * readable.
     *
     * Also when it was created and modified (seconds since 1970, for CREATED() and MODIFIED()).
     *
     * @return array{uuid: string, class: string, data: array, reserved: string, dateTime: string, created: ?float, modified: ?float}|null
     */
    public static function objectOf(RawObject $raw): ?array
    {
        $data = $raw->isExpired() ? null : $raw->decodedData();
        return null !== $data
            ? [
                'uuid' => $raw->uuid, 'class' => (string)$raw->metadata->getClassName(), 'data' => $data, 'reserved' => $raw->metadata->getReservedReferenceName(), 'dateTime' => $raw->metadata->getReservedDateTimeName(),
                'created' => $raw->metadata->getTimestampCreation(), 'modified' => $raw->metadata->getTimestampModification(),
            ]
            : null;
    }

    /**
     * An object as stored; null if it doesn't exist or has expired. Not kept: reading many objects leaves nothing
     * behind (the caches of the storage are bounded too).
     *
     * An object that exists but can't be read (locked by another process beyond the timeout, damaged):
     * - as a row of a source (not strict) it is left out, like an object that doesn't exist: a statement isn't
     *   stopped by an object it doesn't concern, e.g. one locked by a transaction of another process;
     * - as a referenced object (strict) it fails the statement: taken as NULL, a condition like
     *   `ref.name IS NULL` would hold, and a DELETE or UPDATE would change objects whose referenced object is fine.
     *
     * @throws QueryException If strict and the object exists but can't be read.
     */
    private function read(string $uuid, bool $strict = false): ?array
    {
        $this->objectsRead++;
        try {
            $raw = $this->storage->findRaw($uuid);
        } catch (Throwable $e) {
            if (!$strict) {
                return null;
            }
            throw new QueryException(sprintf('The referenced object %s can\'t be read: %s', $uuid, $e->getMessage()), null, $e);
        }
        return null === $raw ? null : self::objectOf($raw);
    }
}
