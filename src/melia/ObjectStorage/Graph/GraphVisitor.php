<?php

namespace melia\ObjectStorage\Graph;

use melia\ObjectStorage\LazyLoadReference;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Reflection\Reflection;
use melia\ObjectStorage\State\StateHandler;
use Closure;
use Generator;
use RuntimeException;
use SplObjectStorage;
use Stringable;
use Throwable;

/**
 * Walks an object graph that was loaded via ObjectStorage::load() and
 * eagerly resolves every LazyLoadReference it finds - in object
 * properties (including dynamically attached ones) and in array cells,
 * recursively - so the resulting graph contains only real objects.
 *
 * Why this is needed: tools that inspect an object graph via Reflection
 * have no idea that a property holding an instance of
 * melia\ObjectStorage\LazyLoadReference is a stand-in for something
 * else, so they draw a node for the proxy itself instead of following
 * the reference. Running the graph through GraphVisitor first forces
 * every reference to resolve (which, per ObjectStorage's own "parent
 * auto-update" behaviour, replaces the proxy in place) before you hand
 * the root object to such a tool. To draw only which stored objects
 * reference which, use buildReferenceGraph() and Export\Dot\DotExporter.
 *
 * Property discovery is delegated to melia\ObjectStorage\Reflection\Reflection
 * so it stays consistent with the rest of the library: it includes
 * properties attached at runtime and handles typed-but-uninitialized,
 * readonly and private properties.
 *
 * Usage:
 *
 *   $storage = new ObjectStorage(__DIR__ . '/var/object-storage');
 *   $root    = $storage->load($uuid);
 *
 *   (new GraphVisitor($storage))->resolve($root);
 *
 *   // or only the connections between stored objects, as a Graphviz file
 *   $graph = (new GraphVisitor($storage))->buildReferenceGraph($root, $uuid);
 *   file_put_contents('graph.dot', (new Export\Dot\DotExporter())->export($graph));
 */
final class GraphVisitor
{
    private SplObjectStorage $visited;

    /** Lazy references that turned out to be unresolvable during the last walk, with the reason. */
    private SplObjectStorage $danglingReferences;

    private int $resolvedCount = 0;

    /** Short class name (without namespace) derived from $lazyReferenceClass, used as a fallback match. */
    private readonly string $lazyReferenceShortName;

    /** Sentinel returned by resolveValue() to tell callers "remove this slot entirely" - never itself written into the graph. */
    private readonly object $danglingMarker;

    /** Whether the last walk had to turn safe mode back off (see guarded()). */
    private bool $safeModeSuppressed = false;

    /** uuid => GraphNode, populated during buildReferenceGraph() to dedupe shared/circular references. */
    private array $nodesByUuid = [];

    public function __construct(
        private readonly ?ObjectStorage $storage = null,
        private readonly string $lazyReferenceClass = LazyLoadReference::class,
        private readonly string $resolverMethod = 'getObject',
        private readonly int $maxDepth = 500,
        /**
         * When true, dangling references are removed from wherever they
         * were found, instead of being left in place as an unresolved proxy:
         *   - object properties are deinitialized (see clearProperty()),
         *     not set to null, so this also works for non-nullable or
         *     union-typed properties such as
         *     `public LazyLoadReference|ChildObject $child;`.
         *   - array cells are unset() (the key disappears; not reindexed).
         */
        private readonly bool $removeDanglingReferences = false,
        /**
         * Probing dangling references can trip ObjectStorage's corruption
         * detection and turn on safe mode, which blocks further writes.
         * When true, a walk that turned safe mode on turns it back off
         * afterwards. Safe mode that was already on before is left alone:
         * that's a pre-existing condition, not something the walk caused.
         */
        private readonly bool $restoreSafeMode = true,
        /**
         * Property name on the lazy-reference proxy that holds the
         * target's UUID, read via the Reflection helper (works regardless
         * of visibility). Used by buildReferenceGraph() to label nodes.
         */
        private readonly string $referenceUuidProperty = 'uuid',
    ) {
        $this->visited = new SplObjectStorage();
        $this->danglingReferences = new SplObjectStorage();
        $this->danglingMarker = new \stdClass();

        $this->lazyReferenceShortName = Reflection::shortName($this->lazyReferenceClass);
    }

    /**
     * Resolves the whole graph reachable from $root in place and
     * returns $root (mutated) for convenience/chaining.
     *
     * Dangling references - lazy references whose target UUID is gone
     * (deleted, expired, corrupted, ...) - are left untouched as proxies
     * by default and are never recursed into; they don't abort the walk.
     * Use getDanglingReferences() afterwards to inspect what was skipped,
     * or pass $removeDanglingReferences = true to the constructor to have
     * them removed instead.
     *
     * If $root itself is a dangling reference, there is no parent
     * property to remove it from, so it is returned as the still-lazy
     * proxy regardless of $removeDanglingReferences.
     */
    public function resolve(object $root): object
    {
        $this->visited = new SplObjectStorage();

        return $this->guarded(function () use ($root): object {
            // The root itself could be a lazy reference (e.g. fished out of an array manually)
            if ($this->isLazyReference($root)) {
                $resolved = $this->resolveReference($root);
                if ($resolved === null) {
                    return $root; // nothing to walk, leave the dangling proxy as-is
                }
                $root = $resolved;
            }

            $this->visitObject($root, 0);

            return $root;
        });
    }

    /**
     * Builds a slim, connections-only graph reachable from $root: no
     * domain data, just which stored object (by UUID) points to which.
     * Unlike resolve(), this does NOT mutate $root or anything it points
     * to - it returns a separate tree of GraphNode / DanglingReference
     * instances, safe to feed straight into DotExporter.
     *
     * $rootUuid is required, since $root doesn't necessarily carry its own
     * UUID. $rootClassName is an optional override - if omitted, the class
     * name is looked up from the metadata of the storage when possible,
     * falling back to get_class(). GraphNode::$isAnonymous flags nodes of
     * anonymous classes, see determineClassInfo().
     *
     * For every object reachable via a lazy reference, only the attributes
     * that are themselves references are kept (as nested GraphNode /
     * DanglingReference values on GraphNode::$references, or - for an array
     * attribute - as an array of just the reference entries, keys
     * preserved). Plain data and plain nested objects are dropped; the walk
     * doesn't descend into them.
     *
     * A given UUID produces one GraphNode instance, reused wherever it is
     * referenced again, so shared/circular references show up as the same
     * instance in several places. Dangling references become
     * DanglingReference instances holding the UUID of the node they were
     * found on. getDanglingReferences()/getResolvedCount() reflect this
     * walk too.
     */
    public function buildReferenceGraph(object $root, string $rootUuid, ?string $rootClassName = null): GraphNode
    {
        $this->nodesByUuid = [];

        return $this->guarded(function () use ($root, $rootUuid, $rootClassName): GraphNode {
            [$className, $isAnonymous] = $this->determineClassInfo($root, $rootUuid, $rootClassName);

            return $this->buildNodeForObject($root, $rootUuid, $className, $isAnonymous);
        });
    }

    /**
     * Whether the last walk had to turn safe mode back off because
     * probing a dangling reference tripped it. Handy to log - it's a
     * signal that some referenced data is actually corrupted or
     * unreadable, not just missing/expired.
     */
    public function wasSafeModeSuppressed(): bool
    {
        return $this->safeModeSuppressed;
    }

    /**
     * Number of LazyLoadReference instances resolved during the last walk.
     */
    public function getResolvedCount(): int
    {
        return $this->resolvedCount;
    }

    /**
     * Number of dangling (unresolvable) references encountered during
     * the last walk.
     */
    public function getDanglingCount(): int
    {
        return count($this->danglingReferences);
    }

    /**
     * The still-lazy proxy objects that could not be resolved during the
     * last walk (e.g. their referenced UUID no longer exists in storage),
     * together with why. They are left where they were found and were not
     * visited any further.
     *
     * 'reason' is the resolver method's exception message if it threw, or
     * a note that it returned something other than an object - useful to
     * tell "storage error" apart from "misconfigured resolver method name".
     *
     * @return list<array{reference: object, reason: string}>
     */
    public function getDanglingReferences(): array
    {
        $result = [];

        foreach ($this->danglingReferences as $reference) {
            $result[] = [
                'reference' => $reference,
                'reason' => $this->danglingReferences->getInfo() ?? 'unknown',
            ];
        }

        return $result;
    }

    /**
     * Runs a walk with fresh counters; if the walk turned safe mode on (it
     * was off before), it is turned back off afterwards. See $restoreSafeMode.
     *
     * @template T
     * @param Closure(): T $walk
     * @return T
     */
    private function guarded(Closure $walk): mixed
    {
        $this->danglingReferences = new SplObjectStorage();
        $this->resolvedCount = 0;
        $this->safeModeSuppressed = false;

        $stateHandler = $this->restoreSafeMode ? $this->storage?->getStateHandler() : null;
        $safeModeWasEnabled = $stateHandler?->safeModeEnabled() ?? false;

        try {
            return $walk();
        } finally {
            if ($stateHandler instanceof StateHandler && !$safeModeWasEnabled && $stateHandler->safeModeEnabled()) {
                $stateHandler->disableSafeMode();
                $this->safeModeSuppressed = true;
            }
        }
    }

    // ---- reference graph ----

    private function buildNodeForObject(object $object, string $uuid, string $className, bool $isAnonymous): GraphNode
    {
        if (isset($this->nodesByUuid[$uuid])) {
            return $this->nodesByUuid[$uuid];
        }

        // Registered before its references are filled in, so a cycle
        // that leads back to this UUID finds this (still-filling-in)
        // node instead of recursing forever.
        $node = new GraphNode($className, $uuid, $isAnonymous);
        $this->nodesByUuid[$uuid] = $node;

        foreach (self::readableProperties(new Reflection($object)) as $propertyName => $value) {
            $references = $this->extractReferences($value, $uuid);
            if ($references !== null) {
                $node->references[$propertyName] = $references;
            }
        }

        return $node;
    }

    /**
     * Returns the "reference shape" of $value for buildReferenceGraph():
     *   - a lazy reference       -> GraphNode (resolved) or DanglingReference
     *   - an array containing at
     *     least one reference    -> array with only the reference
     *                                entries, keys preserved
     *   - anything else          -> null: not a reference, drop it
     *
     * $parentUuid is the UUID of the node $value was found on - used as
     * the DanglingReference's uuid, since a dangling reference has no
     * resolved target to take a UUID from.
     */
    private function extractReferences(mixed $value, string $parentUuid): GraphNode|DanglingReference|array|null
    {
        if (is_array($value)) {
            $references = [];
            foreach ($value as $key => $item) {
                $extracted = $this->extractReferences($item, $parentUuid);
                if ($extracted !== null) {
                    $references[$key] = $extracted;
                }
            }

            return $references === [] ? null : $references;
        }

        if (!is_object($value) || !$this->isLazyReference($value)) {
            return null;
        }

        $resolved = $this->resolveReference($value);
        if ($resolved === null) {
            return new DanglingReference($parentUuid);
        }

        $uuid = $this->extractReferenceUuid($value) ?? ('unknown:' . spl_object_id($value));
        [$className, $isAnonymous] = $this->determineClassInfo($resolved, $uuid, null);

        return $this->buildNodeForObject($resolved, $uuid, $className, $isAnonymous);
    }

    /**
     * The class name to label a graph node with, and whether the class is
     * anonymous (its name then is PHP's generated one, like
     * "class@anonymous/path/to/file.php:12$0"): $explicitClassName if
     * given, else the class name in the metadata of the storage, else
     * get_class().
     *
     * @return array{0: string, 1: bool} [className, isAnonymous]
     */
    private function determineClassInfo(object $object, string $uuid, ?string $explicitClassName): array
    {
        $className = $explicitClassName ?? $this->lookupClassNameFromMetadata($uuid) ?? get_class($object);

        return [$className, Reflection::getReflectionClass($object)->isAnonymous()];
    }

    /**
     * The class name from $storage->getMetadata($uuid)->getClassname(), if
     * the storage exposes that chain; any failure gives null rather than
     * aborting the walk.
     */
    private function lookupClassNameFromMetadata(string $uuid): ?string
    {
        if ($this->storage === null || !method_exists($this->storage, 'getMetadata')) {
            return null;
        }

        try {
            $metadata = $this->storage->getMetadata($uuid);
            $className = is_object($metadata) && method_exists($metadata, 'getClassname') ? $metadata->getClassname() : null;
        } catch (Throwable) {
            return null;
        }

        return is_string($className) && $className !== '' ? $className : null;
    }

    /**
     * Reads $referenceUuidProperty off a lazy-reference proxy. Null if
     * the property doesn't exist, isn't initialized, or its value is
     * neither a string nor Stringable (e.g. a Uuid value object) -
     * callers treat that as "UUID unknown".
     */
    private function extractReferenceUuid(object $reference): ?string
    {
        try {
            $reflection = new Reflection($reference);
            if (!$reflection->initialized($this->referenceUuidProperty)) {
                return null;
            }
            $value = $reflection->get($this->referenceUuidProperty);
        } catch (Throwable) {
            return null;
        }

        return is_string($value) || $value instanceof Stringable ? (string)$value : null;
    }

    // ---- resolving in place ----

    private function visitObject(object $object, int $depth): void
    {
        if ($depth > $this->maxDepth) {
            return; // guard against pathological graphs / bugs in cycle detection
        }

        if ($this->visited->offsetExists($object)) {
            return; // already walked - also breaks circular references
        }
        $this->visited->offsetSet($object);

        if ($this->isLazyReference($object)) {
            return; // resolveValue() unwraps these before recursing; defensive
        }

        $reflection = new Reflection($object);

        foreach (self::readableProperties($reflection) as $propertyName => $value) {
            $resolved = $this->resolveValue($value, $depth + 1);

            if ($resolved === $value) {
                continue; // unchanged, nothing to write back
            }

            if ($resolved === $this->danglingMarker) {
                $this->clearProperty($reflection, $propertyName);
                continue;
            }

            try {
                $reflection->set($propertyName, $resolved);
            } catch (Throwable) {
                // Readonly or otherwise unsettable - LazyLoadReference's own
                // "parent auto-update" has most likely already replaced it
                // in place, so this is not fatal.
            }
        }
    }

    private function resolveValue(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            return $this->resolveArray($value, $depth);
        }

        if (!is_object($value)) {
            return $value;
        }

        if ($this->isLazyReference($value)) {
            $resolved = $this->resolveReference($value);
            if ($resolved === null) {
                return $this->removeDanglingReferences ? $this->danglingMarker : $value;
            }
            $value = $resolved;
        }

        $this->visitObject($value, $depth + 1);

        return $value;
    }

    /**
     * @param array<array-key, mixed> $array
     * @return array<array-key, mixed>
     */
    private function resolveArray(array $array, int $depth): array
    {
        foreach ($array as $key => $item) {
            $resolved = $this->resolveValue($item, $depth + 1);

            if ($resolved === $this->danglingMarker) {
                unset($array[$key]);
                continue;
            }

            $array[$key] = $resolved;
        }

        return $array;
    }

    /**
     * Clears $propertyName on $reflection's target so it no longer holds
     * the dangling reference - deinitialized, not set to null. Returns
     * whether it actually got cleared.
     *
     * Tries Reflection::unset() first. That gives up (returns false) for
     * union types such as `public LazyLoadReference|ChildObject $child;`,
     * so the fallback calls plain unset() from a closure bound to the
     * target's class scope: unset() has no type check and the scope
     * grants access to private/protected properties.
     */
    private function clearProperty(Reflection $reflection, string $propertyName): bool
    {
        try {
            if ($reflection->unset($propertyName)) {
                return true;
            }
        } catch (Throwable) {
            // fall through to the raw-unset fallback below
        }

        $target = $reflection->getTarget();

        try {
            $unsetInScope = Closure::bind(
                function () use ($propertyName): void {
                    unset($this->{$propertyName});
                },
                $target,
                $target::class
            );
            $unsetInScope();

            return !$reflection->initialized($propertyName);
        } catch (Throwable) {
            return false; // give up - the dangling proxy stays in place
        }
    }

    // ---- helpers ----

    /**
     * The initialized, non-static properties of an object that can be
     * read, by name.
     *
     * @return Generator<string, mixed>
     */
    private static function readableProperties(Reflection $reflection): Generator
    {
        foreach ($reflection->getPropertyNames() as $propertyName) {
            // Covers typed-but-never-hydrated declared properties as well
            // as dynamic properties that were unset again.
            if ($reflection->isStatic($propertyName) || !$reflection->initialized($propertyName)) {
                continue;
            }

            try {
                $value = $reflection->get($propertyName);
            } catch (Throwable) {
                continue;
            }

            yield $propertyName => $value;
        }
    }

    /**
     * Whether $object is a lazy-load proxy.
     *
     * Matches against the configured FQCN first, and as a fallback by short
     * class name: if $lazyReferenceClass was configured with the wrong
     * namespace, is_a() silently fails for every object and nothing would
     * ever be resolved.
     */
    private function isLazyReference(object $object): bool
    {
        return is_a($object, $this->lazyReferenceClass)
            || Reflection::getReflectionClass($object)->getShortName() === $this->lazyReferenceShortName;
    }

    /**
     * Resolves a lazy reference; null if it is dangling, i.e. its target
     * could not be loaded: it was found dangling before, the resolver
     * method threw (e.g. the referenced UUID was deleted, expired or is
     * unreadable), or it returned something that isn't an object. A
     * dangling reference is recorded with the reason.
     *
     * A misconfigured $resolverMethod (method doesn't exist at all) is a
     * configuration error, not a dangling reference, and throws.
     */
    private function resolveReference(object $reference): ?object
    {
        if ($this->danglingReferences->offsetExists($reference)) {
            return null; // known to be unresolvable: don't hit storage again
        }

        if (!method_exists($reference, $this->resolverMethod)) {
            throw new RuntimeException(sprintf(
                'Lazy reference of class %s has no %s() method - adjust ' .
                '$resolverMethod in the GraphVisitor constructor to match ' .
                'your installed melia/object-storage version.',
                get_class($reference),
                $this->resolverMethod
            ));
        }

        try {
            $resolved = $reference->{$this->resolverMethod}();
        } catch (Throwable $e) {
            return $this->dangling($reference, sprintf('%s: %s', get_class($e), $e->getMessage()));
        }

        if (!is_object($resolved)) {
            return $this->dangling($reference, sprintf(
                '%s::%s() returned %s instead of an object.',
                get_class($reference),
                $this->resolverMethod,
                get_debug_type($resolved)
            ));
        }

        $this->resolvedCount++;

        return $resolved;
    }

    private function dangling(object $reference, string $reason): null
    {
        $this->danglingReferences->offsetSet($reference, $reason);

        return null;
    }
}
