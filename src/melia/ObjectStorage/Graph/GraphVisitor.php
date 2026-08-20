<?php

namespace melia\ObjectStorage\Graph;

use melia\ObjectStorage\LazyLoadReference;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Reflection\Reflection;
use Closure;
use RuntimeException;
use SplObjectStorage;
use Throwable;

/**
 * Walks an object graph that was loaded via ObjectStorage::load() and
 * eagerly resolves every LazyLoadReference it finds - in object
 * properties (including dynamically attached ones) and in array cells,
 * recursively - so the resulting graph contains only real objects.
 *
 * Why this is needed: sebastian/object-graph's object_graph_dump()
 * inspects properties via Reflection. It has no idea that a property
 * holding an instance of melia\ObjectStorage\LazyLoadReference is a
 * stand-in for something else, so it will happily draw a node for the
 * proxy itself instead of following the reference. Running the graph
 * through GraphVisitor first forces every reference to resolve (which,
 * per ObjectStorage's own "parent auto-update" behaviour, replaces the
 * proxy in place) before you hand the root object to object_graph_dump().
 *
 * Property discovery is delegated to melia\ObjectStorage\Reflection\Reflection
 * so it stays consistent with the rest of the library:
 *   - Reflection::getPropertyNames() unions the class's declared
 *     properties with get_object_vars($target), which is what surfaces
 *     properties attached to the object at runtime (not part of the
 *     class declaration) - those are picked up here without any extra
 *     code.
 *   - Reflection::initialized()/get()/set() handle typed-but-uninitialized
 *     properties and readonly/private access the same way the rest of
 *     ObjectStorage does.
 *
 * ASSUMPTIONS (adjust the constructor args if your installed version
 * differs - I could not fetch the exact source of LazyLoadReference.php
 * from the repo to verify these 1:1, they're taken from the README /
 * quick-start examples):
 *   - Proxy class is melia\ObjectStorage\LazyLoadReference
 *   - It exposes a public getObject(): object method that triggers the
 *     load and returns the real object (as shown in the "Lazy references"
 *     example: `$loaded->owner->getObject();`)
 *
 * Usage:
 *
 *   $storage = new ObjectStorage(__DIR__ . '/var/object-storage');
 *   $root    = $storage->load($uuid);
 *
 *   (new GraphVisitor($storage))->resolve($root);
 *
 *   \SebastianBergmann\ObjectGraph\object_graph_dump('graph.svg', $root);
 */
final class GraphVisitor
{
    private SplObjectStorage $visited;

    /** Lazy references that turned out to be unresolvable during the last resolve() call. */
    private SplObjectStorage $danglingReferences;

    private int $resolvedCount = 0;

    /** Short class name (without namespace) derived from $lazyReferenceClass, used as a fallback match. */
    private readonly string $lazyReferenceShortName;

    /** Set by tryResolveLazyReference() right before it returns null, read by its caller. */
    private ?string $lastDanglingReason = null;

    /** Sentinel returned by resolveValue() to tell callers "remove this slot entirely" - never itself written into the graph. */
    private readonly object $danglingMarker;

    /** Whether the last resolve() call had to turn safe mode back off (see restoreSafeModeIfNeeded()). */
    private bool $safeModeSuppressed = false;

    /** uuid => GraphNode, populated during buildReferenceGraph() to dedupe shared/circular references. */
    private array $nodesByUuid = [];

    public function __construct(
        private readonly ?ObjectStorage $storage = null,
        private readonly string $lazyReferenceClass = LazyLoadReference::class,
        private readonly string $resolverMethod = 'getObject',
        private readonly int $maxDepth = 500,
        /**
         * When true, dangling references are actually removed from
         * wherever they were found, instead of being left in place as
         * an unresolved proxy:
         *   - object properties are deinitialized (see clearProperty())
         *     rather than set to null, so this also works for
         *     non-nullable/union-typed properties such as this
         *     library's own recommended
         *     `public LazyLoadReference|ChildObject $child;` pattern.
         *   - array cells are unset() entirely (the key disappears; not
         *     reindexed - use array_values() afterwards for a dense list).
         */
        private readonly bool $removeDanglingReferences = false,
        /**
         * Probing dangling references (calling the resolver method on a
         * lazy reference whose target is gone/corrupted) can trip
         * ObjectStorage's own corruption detection and flip on safe
         * mode via its StateHandler ($storage->getStateHandler()) - a
         * storage-wide flag that blocks further writes until someone
         * calls disableSafeMode() on that handler. That's an unwanted
         * side effect of what is otherwise a read-only export/diagnostic
         * walk. When true (default) and $storage exposes getStateHandler()
         * whose result exposes both safeModeEnabled() and
         * disableSafeMode() (checked via method_exists() - if anything
         * in that chain is missing this is silently skipped rather than
         * causing a fatal Error), resolve() snapshots safeModeEnabled()
         * before it starts and, if it flips from false to true purely
         * because of this walk, turns it back off again afterwards. If
         * safe mode was already on before resolve() was called, it is
         * left untouched - that's a pre-existing condition, not
         * something this walk caused, and silently clearing it would
         * hide a real problem.
         */
        private readonly bool $restoreSafeMode = true,
        /**
         * Property name on the lazy-reference proxy that holds the
         * target's UUID, read via the Reflection helper (works
         * regardless of visibility). Used by buildReferenceGraph() to
         * label nodes/dangling references without needing to resolve
         * them first just to find out what they point to.
         */
        private readonly string $referenceUuidProperty = 'uuid',
    ) {
        $this->visited = new SplObjectStorage();
        $this->danglingReferences = new SplObjectStorage();
        $this->danglingMarker = new \stdClass();

        $pos = strrpos($this->lazyReferenceClass, '\\');
        $this->lazyReferenceShortName = $pos === false
            ? $this->lazyReferenceClass
            : substr($this->lazyReferenceClass, $pos + 1);
    }

    /**
     * Resolves the whole graph reachable from $root in place and
     * returns $root (mutated) for convenience/chaining.
     *
     * Dangling references - lazy references whose target UUID is gone
     * (deleted, expired, corrupted, ...) - are left untouched as proxies
     * by default and are never recursed into; they don't abort the walk
     * and they don't get visited as if they were real nodes. Use
     * getDanglingReferences() afterwards to inspect what was skipped, or
     * pass $removeDanglingReferences = true to the constructor to have
     * them actually removed instead of left as a proxy.
     *
     * Note: if $root itself is a dangling reference, there is no parent
     * property to remove it from, so it is returned as the still-lazy
     * proxy regardless of $removeDanglingReferences.
     *
     * If $storage was passed to the constructor and safe mode flips on
     * purely because of this walk, it's turned back off again before
     * returning, via $storage->getStateHandler() - see the
     * $restoreSafeMode constructor parameter.
     */
    public function resolve(object $root): object
    {
        $this->visited = new SplObjectStorage();
        $this->danglingReferences = new SplObjectStorage();
        $this->resolvedCount = 0;
        $this->safeModeSuppressed = false;

        $stateHandler = $this->getManageableStateHandler();
        $safeModeWasEnabled = $stateHandler?->safeModeEnabled() ?? false;

        try {
            // The root itself could theoretically be a lazy reference
            // (e.g. you fished it out of an array manually); handle that too.
            if ($this->isLazyReference($root)) {
                $resolvedRoot = $this->tryResolveLazyReference($root);

                if ($resolvedRoot === null) {
                    $this->danglingReferences->offsetSet($root, $this->lastDanglingReason);
                    return $root; // nothing to walk, leave the dangling proxy as-is
                }

                $root = $resolvedRoot;
            }

            $this->visitObject($root, 0);

            return $root;
        } finally {
            $this->restoreSafeModeIfNeeded($stateHandler, $safeModeWasEnabled);
        }
    }

    /**
     * Turns safe mode back off if (and only if) this resolve() call is
     * the reason it's on: it wasn't enabled when resolve() started, but
     * is now. See the $restoreSafeMode constructor parameter for why.
     */
    private function restoreSafeModeIfNeeded(?object $stateHandler, bool $safeModeWasEnabledBefore): void
    {
        if ($stateHandler === null) {
            return;
        }

        if (!$safeModeWasEnabledBefore && $stateHandler->safeModeEnabled()) {
            $stateHandler->disableSafeMode();
            $this->safeModeSuppressed = true;
        }
    }

    /**
     * Returns $storage->getStateHandler() if it's usable for safe-mode
     * management, or null otherwise (missing $storage,
     * $restoreSafeMode = false, ObjectStorage doesn't expose
     * getStateHandler(), or the handler it returns doesn't expose both
     * safeModeEnabled() and disableSafeMode()). Fetched once per
     * resolve() call rather than re-derived, since we can't assume
     * getStateHandler() is cheap or returns the same instance every time.
     */
    private function getManageableStateHandler(): ?object
    {
        if ($this->storage === null
            || !$this->restoreSafeMode
            || !method_exists($this->storage, 'getStateHandler')
        ) {
            return null;
        }

        $stateHandler = $this->storage->getStateHandler();

        if (!is_object($stateHandler)
            || !method_exists($stateHandler, 'safeModeEnabled')
            || !method_exists($stateHandler, 'disableSafeMode')
        ) {
            return null;
        }

        return $stateHandler;
    }

    /**
     * Whether the last resolve() call had to turn safe mode back off
     * because probing a dangling reference tripped it. Handy to log -
     * it's a signal that some referenced data is actually corrupted or
     * unreadable, not just missing/expired.
     */
    public function wasSafeModeSuppressed(): bool
    {
        return $this->safeModeSuppressed;
    }

    /**
     * Builds a slim, connections-only graph reachable from $root: no
     * domain data, just which stored object (by UUID) points to which.
     * Unlike resolve(), this does NOT mutate $root or anything it
     * points to - it returns an entirely separate tree of GraphNode /
     * DanglingReference instances, safe to feed straight into
     * object_graph_dump() when you want to see the connection graph
     * rather than the full object graph.
     *
     * $rootUuid is required rather than inferred, since $root - loaded
     * via ObjectStorage::load($uuid) - doesn't necessarily carry its own
     * UUID as an accessible attribute; the caller already has it from
     * having called load() in the first place. $rootClassName is
     * likewise an optional override - if omitted, the class name is
     * looked up via $storage->getMetadata($uuid)->getClassname() when
     * possible, falling back to get_class(). GraphNode::$isAnonymous
     * flags nodes whose class name is a generated PHP anonymous-class
     * name rather than a real one - see determineClassInfo().
     *
     * For every object reachable via a lazy reference, only the
     * attributes that are themselves references are kept (as nested
     * GraphNode/DanglingReference values on GraphNode::$references, or -
     * for an array attribute - as an array containing just the
     * reference entries, original keys preserved). Everything else -
     * plain data attributes, and plain (non-reference) nested objects -
     * is dropped entirely; this walk does not descend into
     * non-reference objects looking for references buried further
     * inside them.
     *
     * A given UUID only ever produces one GraphNode instance, reused
     * wherever that UUID is referenced again - shared/circular
     * references show up as that same instance appearing in multiple
     * places in the tree, which object_graph_dump() detects and
     * represents correctly on its own.
     *
     * Dangling references (see resolve()'s docblock) become
     * DanglingReference instances holding the UUID of the node they were
     * found on (their own target's UUID can't be determined without
     * successfully resolving them). getDanglingReferences()/
     * getResolvedCount() reflect this walk too, same as they do for
     * resolve().
     *
     * If $storage was passed to the constructor and safe mode flips on
     * purely because of this walk, it's turned back off again before
     * returning - see the $restoreSafeMode constructor parameter and
     * resolve()'s docblock.
     */
    public function buildReferenceGraph(object $root, string $rootUuid, ?string $rootClassName = null): GraphNode
    {
        $this->nodesByUuid = [];
        $this->danglingReferences = new SplObjectStorage();
        $this->resolvedCount = 0;
        $this->safeModeSuppressed = false;

        $stateHandler = $this->getManageableStateHandler();
        $safeModeWasEnabled = $stateHandler?->safeModeEnabled() ?? false;

        try {
            [$className, $isAnonymous] = $this->determineClassInfo($root, $rootUuid, $rootClassName);

            return $this->buildNodeForObject($root, $rootUuid, $className, $isAnonymous);
        } finally {
            $this->restoreSafeModeIfNeeded($stateHandler, $safeModeWasEnabled);
        }
    }

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

        $reflection = new Reflection($object);

        foreach ($reflection->getPropertyNames() as $propertyName) {
            if ($reflection->isStatic($propertyName) || !$reflection->initialized($propertyName)) {
                continue;
            }

            try {
                $value = $reflection->get($propertyName);
            } catch (Throwable) {
                continue;
            }

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
     *                                entries, other items dropped, keys
     *                                preserved
     *   - anything else          -> null, meaning "not a reference,
     *                                drop this attribute"
     *
     * $parentUuid is the UUID of the node $value was found on - used as
     * the DanglingReference's uuid, since a dangling reference has no
     * resolved target to pull an actual UUID from.
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

        if ($this->danglingReferences->offsetExists($value)) {
            return new DanglingReference($parentUuid);
        }

        $resolved = $this->tryResolveLazyReference($value);

        if ($resolved === null) {
            $this->danglingReferences->offsetSet($value, $this->lastDanglingReason);
            return new DanglingReference($parentUuid);
        }

        $uuid = $this->extractReferenceUuid($value) ?? ('unknown:' . spl_object_id($value));
        [$className, $isAnonymous] = $this->determineClassInfo($resolved, $uuid, null);

        return $this->buildNodeForObject($resolved, $uuid, $className, $isAnonymous);
    }

    /**
     * Determines the class name (and whether it's a real one) to label
     * a graph node with:
     *   1. $explicitClassName, if given (caller override)
     *   2. $storage->getMetadata($uuid)->getClassname(), if $storage is
     *      set and exposes that chain (checked via method_exists() at
     *      each step; any failure - unknown UUID, missing method,
     *      thrown exception - just falls through rather than aborting
     *      the walk)
     *   3. get_class($object) as a last resort - for an anonymous class
     *      this is PHP's generated name like
     *      "class@anonymous/path/to/file.php:12$0", which is why
     *      $isAnonymous exists: callers can render/handle that case
     *      explicitly instead of treating it as a normal class name.
     *
     * @return array{0: string, 1: bool} [className, isAnonymous]
     */
    private function determineClassInfo(object $object, string $uuid, ?string $explicitClassName): array
    {
        $isAnonymous = (new \ReflectionClass($object))->isAnonymous();

        $className = $explicitClassName ?? $this->lookupClassNameFromMetadata($uuid);
        $className ??= get_class($object);

        return [$className, $isAnonymous];
    }

    private function lookupClassNameFromMetadata(string $uuid): ?string
    {
        if ($this->storage === null || !method_exists($this->storage, 'getMetadata')) {
            return null;
        }

        try {
            $metadata = $this->storage->getMetadata($uuid);
        } catch (Throwable) {
            return null;
        }

        if (!is_object($metadata) || !method_exists($metadata, 'getClassname')) {
            return null;
        }

        try {
            $className = $metadata->getClassname();
        } catch (Throwable) {
            return null;
        }

        return is_string($className) && $className !== '' ? $className : null;
    }

    /**
     * Reads $referenceUuidProperty off a lazy-reference proxy via the
     * Reflection helper (works regardless of visibility). Returns null
     * if the property doesn't exist, isn't initialized, or its value is
     * neither a string nor Stringable - callers treat that as "UUID
     * unknown" rather than failing the whole walk over it.
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

        if (is_string($value)) {
            return $value;
        }

        // Some libraries use a dedicated Uuid value object instead of a
        // raw string - accept anything that can render itself as one.
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return null;
    }

    /**
     * Number of LazyLoadReference instances resolved during the last
     * resolve() call. Handy for logging/asserting in tests.
     */
    public function getResolvedCount(): int
    {
        return $this->resolvedCount;
    }

    /**
     * Number of dangling (unresolvable) references encountered during
     * the last resolve() call.
     */
    public function getDanglingCount(): int
    {
        return count($this->danglingReferences);
    }

    /**
     * The still-lazy proxy objects that could not be resolved during the
     * last resolve() call (e.g. their referenced UUID no longer exists
     * in storage), together with why. They are left exactly where they
     * were found in the graph and were not visited any further.
     *
     * 'reason' is:
     *   - the resolver method's exception message, if it threw; or
     *   - a note that it returned something other than an object
     *     (e.g. null, mirroring ObjectStorage::load()'s nullable return
     *     for expired/corrupted objects) - useful to tell "storage error"
     *     apart from "misconfigured resolver method name".
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
            // Shouldn't normally happen (resolveValue() unwraps these
            // before recursing), but be defensive.
            return;
        }

        $reflection = new Reflection($object);

        foreach ($reflection->getPropertyNames() as $propertyName) {
            if ($reflection->isStatic($propertyName)) {
                continue;
            }

            // Covers typed-but-never-hydrated declared properties as well
            // as dynamic properties that were unset again.
            if (!$reflection->initialized($propertyName)) {
                continue;
            }

            try {
                $value = $reflection->get($propertyName);
            } catch (Throwable) {
                continue;
            }

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
                // Readonly or otherwise unsettable - LazyLoadReference's
                // own "parent auto-update" (triggered inside
                // tryResolveLazyReference()) has most likely already
                // replaced it in place, so this is not fatal.
            }
        }
    }

    /**
     * Clears $propertyName on $reflection's target so it no longer holds
     * the dangling reference - actually removed (deinitialized), not set
     * to null. Returns whether it actually got cleared.
     *
     * Tries Reflection::unset() first (consistent with the rest of the
     * library). That method falls back to a type-based default only for
     * ReflectionNamedType and gives up - returning false, not throwing -
     * for anything else. Notably that includes ReflectionUnionType,
     * which is exactly what a property declared the way this library's
     * own README recommends for lazy-loadable properties has, e.g.
     * `public LazyLoadReference|ChildObject $child;`. So for that exact
     * pattern, Reflection::unset() silently does nothing.
     *
     * The fallback binds a closure to the target's own class scope and
     * calls plain unset() from inside it. That deinitializes a typed
     * property instead of assigning it a value (no type check applies
     * to unset(), unlike assignment) and works for private/protected
     * properties too, since scope - not caller context - is what
     * governs visibility here.
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

    private function resolveValue(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            return $this->resolveArray($value, $depth);
        }

        if (!is_object($value)) {
            return $value;
        }

        if ($this->isLazyReference($value)) {
            if ($this->danglingReferences->offsetExists($value)) {
                // Already known to be unresolvable - don't hit storage
                // again and don't visit it.
                return $this->removeDanglingReferences ? $this->danglingMarker : $value;
            }

            $resolved = $this->tryResolveLazyReference($value);

            if ($resolved === null) {
                $this->danglingReferences->offsetSet($value, $this->lastDanglingReason);
                return $this->removeDanglingReferences ? $this->danglingMarker : $value;
            }

            $this->visitObject($resolved, $depth + 1);
            return $resolved;
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
     * Whether $object is a lazy-load proxy.
     *
     * Matches against the configured FQCN first. As a fallback, it also
     * matches by short class name ("LazyLoadReference" without its
     * namespace): if $lazyReferenceClass was configured with the wrong
     * namespace, `instanceof`/`is_a()` against that string silently
     * returns false for every object - no error, no exception, it just
     * never resolves anything, which is exactly the "DOT still shows
     * LazyLoadReference nodes" symptom. The short-name check makes
     * detection resilient to that without needing the exact namespace.
     */
    private function isLazyReference(object $object): bool
    {
        if (is_a($object, $this->lazyReferenceClass)) {
            return true;
        }

        return (new \ReflectionClass($object))->getShortName() === $this->lazyReferenceShortName;
    }

    /**
     * Attempts to resolve a lazy reference. Returns null - rather than
     * throwing - when the reference is dangling, i.e. its target could
     * not be loaded: either the resolver method threw (e.g. the
     * library's own not-found/corruption exception because the
     * referenced UUID was deleted, expired or is otherwise unreadable),
     * or it returned something that isn't an object (mirroring
     * ObjectStorage::load()'s nullable return for expired objects).
     * In both cases, $lastDanglingReason is set with a human-readable
     * explanation the caller can attach to the dangling reference.
     *
     * A misconfigured $resolverMethod (method doesn't exist at all) is
     * treated as a programming/config error, not a dangling reference,
     * and still throws immediately.
     */
    private function tryResolveLazyReference(object $reference): ?object
    {
        $this->lastDanglingReason = null;

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
            $this->lastDanglingReason = sprintf(
                '%s: %s',
                get_class($e),
                $e->getMessage()
            );
            return null;
        }

        if (!is_object($resolved)) {
            $this->lastDanglingReason = sprintf(
                '%s::%s() returned %s instead of an object.',
                get_class($reference),
                $this->resolverMethod,
                get_debug_type($resolved)
            );
            return null;
        }

        $this->resolvedCount++;

        return $resolved;
    }
}