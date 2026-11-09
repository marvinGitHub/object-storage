<?php

namespace melia\ObjectStorage\Query;

use AllowDynamicProperties;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Serialization\DateTimeValue;
use melia\ObjectStorage\Strategy\StrategyInterface;
use melia\ObjectStorage\Transfer\RawObject;
use melia\ObjectStorage\UUID\Validator as UuidValidator;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

/**
 * Runs INSERT, UPDATE, DELETE and ALTER CLASS … DROP ATTRIBUTE on the objects as stored, like copying and moving (ObjectStorage::writeRaw()): the
 * classes aren't needed, and objects aren't loaded and stored again, which would write their referenced objects
 * along. The data of an object is written with a new checksum and revision; constraints (unique indexes) are checked
 * and search indexes notified as for any write.
 *
 * - If the class can be loaded, values are checked against the types of its properties (int, float, string, bool,
 *   dates, classes for REF(), nullable), and properties it doesn't declare are refused unless it allows dynamic ones.
 *   A property declared as a date takes a date or a text that is one ('2026-01-01', UTC without offset); dates are
 *   written inline as the storage writes them (see Serialization\DateTimeValue).
 * - All values are computed and checked before the first object is written: an error writes nothing.
 * - Each object is changed under its exclusive lock: read again, its condition checked again (another process may
 *   have changed it), then written. If writing an object fails (e.g. a unique value), the objects written before
 *   stay written, and the error says how many.
 * - A dry run computes and checks everything and returns the objects that would be written, writing nothing.
 *
 * @internal Not part of the public API; may change in any release.
 */
final class Writer
{
    public function __construct(private readonly ObjectStorage $storage, private readonly Executor $executor)
    {
    }

    /**
     * @throws QueryException
     */
    public function run(WritePlan $plan, bool $dryRun = false): Result
    {
        $class = $this->executor->classOf($plan->source);
        $uuids = match ($plan->statement) {
            WritePlan::INSERT => $this->insert($plan, $class, $dryRun),
            WritePlan::UPDATE => $this->update($plan, $class, $dryRun),
            WritePlan::DELETE => $this->delete($plan, $dryRun),
            WritePlan::DROP => $this->drop($plan, $class, $dryRun),
        };
        return new Result(['UUID'], array_map(static fn(?string $uuid): array => [$uuid], $uuids), $plan->statement, $dryRun);
    }

    // ---- INSERT ----

    /**
     * @return list<?string> the UUIDs of the new objects (null in a dry run)
     */
    private function insert(WritePlan $plan, string $class, bool $dryRun): array
    {
        if (!class_exists($class)) {
            throw new QueryException(sprintf('Class %s isn\'t loadable here: INSERT needs it, to check the values', $class), $plan->source->position);
        }
        // computed and checked first: an error writes nothing
        $objects = [];
        foreach ($plan->rows as $values) {
            $data = [];
            foreach ($plan->properties as $i => $property) {
                $data[$property] = $this->executor->evaluate($values[$i], []);
            }
            $objects[] = $this->dataOf($class, $data, $this->defaults(new ReflectionClass($class), $data), Metadata::RESERVED_NAME_DEFAULTS);
        }
        if ($dryRun) {
            return array_fill(0, count($objects), null);
        }

        $uuids = [];
        foreach ($objects as [$data, $reservedNames]) {
            try {
                $uuid = $this->storage->getNextAvailableUuid();
                $metadata = new Metadata();
                $metadata->setUuid($uuid);
                $metadata->setClassName($class);
                $metadata->setVersion(Metadata::VERSION);
                $metadata->setTimestampCreation($now = microtime(true));
                $metadata->setTimestampModification($now);
                foreach ($reservedNames as $kind => $name) {
                    $metadata->setReservedName($kind, $name);
                }
                $metadata->setChecksumAlgorithm($this->storage->getStrategy()?->getChecksumAlgorithm() ?? ObjectStorage::CHECKSUM_ALGORITHM_DEFAULT);
                $json = $this->serialize($data);
                $metadata->setChecksum(hash($metadata->getChecksumAlgorithm(), $json));
                $metadata->setRevision(1);
                $this->storage->writeRaw(new RawObject($uuid, $json, $metadata));
                $uuids[] = $uuid;
            } catch (Throwable $e) {
                throw $this->stopped('inserted', $uuids, count($objects), $e);
            }
        }
        return $uuids;
    }

    /**
     * The values of the properties an INSERT doesn't set: their defaults (also those of promoted parameters of the
     * constructor, which loading doesn't call), NULL if their type allows it; a property without either needs a
     * value, like a column NOT NULL.
     *
     * @return array<string, mixed>
     */
    private function defaults(ReflectionClass $class, array $given): array
    {
        $parameters = [];
        foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isPromoted() && $parameter->isDefaultValueAvailable()) {
                $parameters[$parameter->getName()] = $parameter->getDefaultValue();
            }
        }

        $defaults = [];
        foreach ($class->getProperties() as $property) {
            $name = $property->getName();
            if ($property->isStatic() || array_key_exists($name, $given)) {
                continue;
            }
            if (array_key_exists($name, $parameters)) {
                $value = $parameters[$name];
            } elseif ($property->hasDefaultValue()) {
                $value = $property->getDefaultValue();
            } elseif ($property->getType()?->allowsNull() ?? true) {
                $value = null;
            } else {
                throw new QueryException(sprintf('%s of %s needs a value: it has no default', $name, $class->getName()));
            }
            if ($value instanceof DateTimeInterface) {
                $value = DateValue::of($value);
            } elseif (is_object($value) || (is_array($value) && str_contains((string)json_encode($value), '{'))) {
                throw new QueryException(sprintf('%s of %s needs a value: its default can\'t be written', $name, $class->getName()));
            }
            $defaults[$name] = $value;
        }
        return $defaults;
    }

    // ---- UPDATE ----

    /**
     * @return list<string> the UUIDs of the objects changed (objects whose values were already the new ones aren't)
     */
    private function update(WritePlan $plan, string $class, bool $dryRun): array
    {
        $alias = $plan->source->alias;

        // computed and checked first: an error writes nothing; only the UUIDs are kept, the objects are read again
        $uuids = [];
        foreach ($this->executor->matching($plan->source, $plan->where) as $row) {
            $this->updated($plan, $class, $row[$alias]);
            $uuids[] = $row[$alias]['uuid'];
        }
        if ($dryRun) {
            return $uuids;
        }

        return $this->rewrite($uuids, 'updated', function (array $object) use ($plan, $class, $alias): ?array {
            if (null !== $plan->where && true !== Values::truth($this->executor->evaluate($plan->where, [$alias => $object]))) {
                return null;
            }
            return $this->updated($plan, $class, $object);
        });
    }

    /**
     * Changes objects one after another, each under its exclusive lock: read again (another process may have changed
     * or deleted it), changed, written with a new checksum and revision if its data changed.
     *
     * @param list<string> $uuids
     * @param callable(array): (array{0: array, 1: array<string, string>}|null) $change The new data and reserved
     *        names of an object (see Executor::objectOf()); null to leave it.
     * @return list<string> the UUIDs of the objects changed
     */
    private function rewrite(array $uuids, string $done, callable $change): array
    {
        $changed = [];
        $lockAdapter = $this->storage->getLockAdapter();
        foreach ($uuids as $uuid) {
            $lockedHere = false;
            try {
                if (null !== $lockAdapter && !$lockAdapter->hasActiveExclusiveLock($uuid)) {
                    $lockAdapter->acquireExclusiveLock($uuid);
                    $lockedHere = true;
                }
                $raw = $this->current($uuid);
                $object = null === $raw ? null : Executor::objectOf($raw);
                $new = null === $object ? null : $change($object);
                if (null === $new) {
                    continue;
                }
                [$data, $reservedNames] = $new;
                $json = $this->serialize($data);
                if ($json === $raw->data) {
                    continue; // nothing changes
                }
                $metadata = clone $raw->metadata;
                foreach ($reservedNames as $kind => $name) {
                    $metadata->setReservedName($kind, $name);
                }
                $metadata->setChecksum(hash($metadata->getChecksumAlgorithm(), $json));
                $metadata->setRevision($metadata->getRevision() + 1);
                $metadata->setTimestampModification(microtime(true));
                $this->storage->writeRaw(new RawObject($uuid, $json, $metadata), true);
                $changed[] = $uuid;
            } catch (Throwable $e) {
                throw $this->stopped($done, $changed, count($uuids), $e);
            } finally {
                if ($lockedHere && $lockAdapter->isLockedByThisProcess($uuid)) {
                    $lockAdapter->releaseLock($uuid);
                }
            }
        }
        return $changed;
    }

    /**
     * The data of an object with the values of UPDATE, computed from its values before.
     *
     * @return array{0: array, 1: array<string, string>} the data and the reserved names (kind => name)
     */
    private function updated(WritePlan $plan, string $class, array $object): array
    {
        $values = [];
        foreach ($plan->properties as $i => $property) {
            $values[$property] = $this->executor->evaluate($plan->rows[0][$i], [$plan->source->alias => $object]);
        }
        return $this->dataOf($class, $values, $object['data'], [Metadata::RESERVED_REFERENCE => $object['reserved'], Metadata::RESERVED_DATETIME => $object['dateTime']]);
    }

    // ---- ALTER CLASS … DROP ATTRIBUTE ----

    /**
     * Removes properties from the stored data of all objects of the class, e.g. after removing them from the class.
     * A property the class (if loadable) still declares isn't dropped: loading would leave it at its default, or
     * uninitialized.
     *
     * @return list<string> the UUIDs of the objects that had one of the properties
     */
    private function drop(WritePlan $plan, string $class, bool $dryRun): array
    {
        if (class_exists($class)) {
            $reflection = new ReflectionClass($class);
            foreach ($plan->properties as $property) {
                if ($reflection->hasProperty($property) && !$reflection->getProperty($property)->isStatic()) {
                    throw new QueryException(sprintf('%s declares the property %s: remove it from the class first, then drop it from the stored objects', $class, $property), $plan->source->position);
                }
            }
        }

        $alias = $plan->source->alias;
        $keys = array_fill_keys($plan->properties, true);
        $uuids = [];
        foreach ($this->executor->matching($plan->source, null) as $row) {
            if ([] !== array_intersect_key($row[$alias]['data'], $keys)) {
                $uuids[] = $row[$alias]['uuid'];
            }
        }
        if ($dryRun) {
            return $uuids;
        }

        return $this->rewrite($uuids, 'changed', static function (array $object) use ($keys): ?array {
            $data = array_diff_key($object['data'], $keys);
            if (count($data) === count($object['data'])) {
                return null;
            }
            return [$data, [Metadata::RESERVED_REFERENCE => $object['reserved'], Metadata::RESERVED_DATETIME => $object['dateTime']]];
        });
    }

    // ---- DELETE ----

    /**
     * @return list<string> the UUIDs of the objects deleted
     */
    private function delete(WritePlan $plan, bool $dryRun): array
    {
        $alias = $plan->source->alias;
        $uuids = [];
        foreach ($this->executor->matching($plan->source, $plan->where) as $row) {
            $uuids[] = $row[$alias]['uuid'];
        }
        if ($dryRun) {
            return $uuids;
        }

        $deleted = [];
        $lockAdapter = $this->storage->getLockAdapter();
        foreach ($uuids as $uuid) {
            $lockedHere = false;
            try {
                if (null !== $lockAdapter && !$lockAdapter->hasActiveExclusiveLock($uuid)) {
                    $lockAdapter->acquireExclusiveLock($uuid);
                    $lockedHere = true;
                }
                $raw = $this->current($uuid);
                $object = null === $raw ? null : Executor::objectOf($raw);
                if (null === $object || (null !== $plan->where && true !== Values::truth($this->executor->evaluate($plan->where, [$alias => $object])))) {
                    continue;
                }
                // with the child deletion policy of the storage, and its events (indexes follow)
                $this->storage->delete($uuid);
                $deleted[] = $uuid;
            } catch (Throwable $e) {
                throw $this->stopped('deleted', $deleted, count($uuids), $e);
            } finally {
                if ($lockedHere && $lockAdapter->isLockedByThisProcess($uuid)) {
                    $lockAdapter->releaseLock($uuid);
                }
            }
        }
        return $deleted;
    }

    // ---- values ----

    /** the object as stored right now; null if it is gone */
    private function current(string $uuid): ?RawObject
    {
        $this->storage->removeFromCache($uuid);
        if (!$this->storage->exists($uuid)) {
            return null;
        }
        return $this->storage->readRaw($uuid);
    }

    /**
     * The data of an object with new values: checked against the class, references written as references.
     *
     * @param array<string, mixed> $values Property => value (Reference for REF(), DateValue for dates).
     * @param array $data The data before.
     * @param array<string, string> $reservedNames The reserved names of the data before (kind => name).
     * @return array{0: array, 1: array<string, string>} the data, sorted by property as the storage writes it, and
     *         the reserved names (others if a property has their name)
     */
    private function dataOf(string $class, array $values, array $data, array $reservedNames): array
    {
        $reflection = class_exists($class) ? new ReflectionClass($class) : null;
        foreach ($values as $property => $value) {
            if ($value instanceof Reference) {
                $this->checkReference($value, $class, $property, $reflection);
            } elseif (is_array($value) || (is_object($value) && !$value instanceof DateValue)) {
                throw new QueryException(sprintf('%s of %s: lists and objects can\'t be written yet, only values and REF()', $property, $class));
            }
            if (null !== $reflection) {
                $value = $this->checked($reflection, $property, $value);
            }
            $data[$property] = $value;
        }

        // the reserved names must not be properties; references and dates written before keep their values
        $chosen = Metadata::chooseReservedNames($reservedNames, array_keys($data));
        $data = $this->withReferences($data, $reservedNames[Metadata::RESERVED_REFERENCE], $chosen[Metadata::RESERVED_REFERENCE]);
        $data = $this->withDates($data, $reservedNames[Metadata::RESERVED_DATETIME], $chosen[Metadata::RESERVED_DATETIME]);

        ksort($data);
        return [$data, $chosen];
    }

    /**
     * Dates written with the reserved key: those written before keep their value, new ones (DateValue) are written as
     * the storage writes them.
     */
    private function withDates(array $data, string $before, string $reserved): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof DateValue) {
                $data[$key] = $value->stored($reserved);
            } elseif (DateTimeValue::isEncoded($value, $before)) {
                if ($before !== $reserved) {
                    $data[$key] = [$reserved => $value[$before]] + $value;
                    unset($data[$key][$before]);
                }
            } elseif (is_array($value)) {
                $data[$key] = $this->withDates($value, $before, $reserved);
            }
        }
        return $data;
    }

    /**
     * References written with the reserved name: those written before keep their target, new ones (Reference) get it.
     */
    private function withReferences(array $data, string $before, string $reserved): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof Reference) {
                $data[$key] = [$reserved => $value->uuid];
            } elseif (is_array($value)) {
                if (1 === count($value) && isset($value[$before]) && is_string($value[$before]) && UuidValidator::validate($value[$before])) {
                    $data[$key] = [$reserved => $value[$before]];
                } else {
                    $data[$key] = $this->withReferences($value, $before, $reserved);
                }
            }
        }
        return $data;
    }

    private function checkReference(Reference $reference, string $class, string $property, ?ReflectionClass $reflection): void
    {
        $target = UuidValidator::validate($reference->uuid) ? $this->storage->loadMetadata($reference->uuid) : null;
        if (null === $target || !$this->storage->exists($reference->uuid)) {
            throw new QueryException(sprintf('REF(\'%s\') for %s of %s: there is no such object', $reference->uuid, $property, $class));
        }
        // a typed property takes objects of its class (or a subclass)
        $type = $reflection?->hasProperty($property) ? $reflection->getProperty($property)->getType() : null;
        if (null !== $type && !$this->acceptsObject($type, $target->getClassName())) {
            throw new QueryException(sprintf('%s of %s is %s, REF(\'%s\') is a %s', $property, $class, $type, $reference->uuid, $target->getClassName()));
        }
    }

    private function acceptsObject(ReflectionType $type, string $class): bool
    {
        foreach ($type instanceof ReflectionUnionType ? $type->getTypes() : [$type] as $named) {
            if (!$named instanceof ReflectionNamedType) {
                return true; // intersection types: not checked
            }
            $name = $named->getName();
            if (in_array($name, ['mixed', 'object'], true) || (class_exists($class) && is_a($class, $name, true)) || (!class_exists($class) && !$named->isBuiltin())) {
                return true;
            }
        }
        return false;
    }

    /**
     * A value checked against the property of the class, converted where nothing is lost (3.0 for an int is 3).
     */
    private function checked(ReflectionClass $class, string $property, mixed $value): mixed
    {
        if (!$class->hasProperty($property)) {
            $dynamic = 'stdClass' === $class->getName() || $class->hasMethod('__set') || [] !== $class->getAttributes(AllowDynamicProperties::class);
            if (!$dynamic) {
                throw new QueryException(sprintf('%s has no property %s', $class->getName(), $property));
            }
            return $value;
        }
        $reflection = new ReflectionProperty($class->getName(), $property);
        if ($reflection->isStatic()) {
            throw new QueryException(sprintf('%s::$%s is static', $class->getName(), $property));
        }
        $type = $reflection->getType();
        if (null === $type || $value instanceof Reference) {
            return $value;
        }
        if ($value instanceof DateValue && $type instanceof ReflectionNamedType && 'object' === $type->getName()) {
            return $value;
        }
        if (null === $value) {
            if (!$type->allowsNull()) {
                throw new QueryException(sprintf('%s of %s is %s: NULL isn\'t possible', $property, $class->getName(), $type));
            }
            return null;
        }
        foreach ($type instanceof ReflectionUnionType ? $type->getTypes() : [$type] as $named) {
            if (!$named instanceof ReflectionNamedType) {
                return $value;
            }
            $converted = match ($named->getName()) {
                'mixed' => $value,
                'int' => is_int($value) ? $value : (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX ? (int)$value : null),
                'float' => is_int($value) || is_float($value) ? (float)$value : null,
                'string' => is_string($value) ? $value : null,
                'bool' => is_bool($value) ? $value : null,
                'true' => true === $value ? true : null,
                'false' => false === $value ? false : null,
                default => self::dateFor($named, $value),
            };
            if (null !== $converted) {
                return $converted;
            }
        }
        throw new QueryException(sprintf('%s of %s is %s, not %s', $property, $class->getName(), $type, self::describe($value)));
    }

    /**
     * A date for a property declared as a date class (DateTimeInterface, DateTimeImmutable, DateTime or a subclass):
     * a date, or a text that is one; written with the declared class if it isn't one already. Null otherwise.
     */
    private static function dateFor(ReflectionNamedType $type, mixed $value): ?DateValue
    {
        $name = $type->getName();
        if ($type->isBuiltin() || !(is_a($name, DateTimeInterface::class, true))) {
            return null;
        }
        $date = DateValue::from($value);
        if (null === $date) {
            return null;
        }
        if (is_a($date->class, $name, true)) {
            return $date;
        }
        // an interface (DateTimeInterface) keeps the class; a class of its own is written as declared
        return is_a($name, DateTimeImmutable::class, true) || is_a($name, DateTime::class, true) ? $date->as($name) : $date;
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            $value instanceof DateValue => 'the date ' . $value->utc,
            is_string($value) => sprintf("the text '%s'", mb_strimwidth($value, 0, 40, '…')),
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_float($value) => 'the number ' . json_encode($value, JSON_PRESERVE_ZERO_FRACTION),
            default => 'the number ' . $value,
        };
    }

    private function serialize(array $data): string
    {
        $strategy = $this->storage->getStrategy();
        return $strategy?->serialize($data, $strategy->getMaxDepth()) ?? json_encode($data, JSON_THROW_ON_ERROR, StrategyInterface::DEFAULT_MAX_DEPTH);
    }

    private function stopped(string $done, array $uuids, int $count, Throwable $e): QueryException
    {
        $message = $e instanceof QueryException ? $e->getMessage() : $e->getMessage() . ($e->getPrevious() ? ': ' . $e->getPrevious()->getMessage() : '');
        return new QueryException(sprintf('Stopped after %d of %d objects were %s: %s', count($uuids), $count, $done, $message), null, $e);
    }
}
