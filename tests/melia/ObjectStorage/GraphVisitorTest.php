<?php

declare(strict_types=1);

namespace tests\melia\ObjectStorage;

use AllowDynamicProperties;
use melia\ObjectStorage\Graph\GraphVisitor;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\State\StateHandler;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Throwable;

// ---------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------

/**
 * Stand-in for melia\ObjectStorage\LazyLoadReference, kept independent
 * from the real class so these tests don't need a real ObjectStorage
 * instance or backing files. Pass FakeLazyReference::class as
 * $lazyReferenceClass to the GraphVisitor under test.
 */
final class FakeLazyReference
{
    public int $calls = 0;

    /**
     * @param mixed $outcome What getObject() produces:
     *   - an object    -> resolves successfully to it
     *   - null         -> dangling (mirrors ObjectStorage::load()
     *                     returning null for an expired/missing object)
     *   - a Throwable  -> dangling (getObject() throws it)
     */
    public function __construct(private readonly mixed $outcome)
    {
    }

    public function getObject(): mixed
    {
        $this->calls++;

        if ($this->outcome instanceof Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}

final class ChildFixture
{
    public FakeLazyReference|GrandchildFixture|null $child = null;

    public function __construct(public string $name = 'child')
    {
    }
}

final class GrandchildFixture
{
    public function __construct(public string $name = 'grandchild')
    {
    }
}

#[AllowDynamicProperties]
final class ParentFixture
{
    /** Non-nullable union, no default - the exact pattern the library recommends for lazy-loadable properties. */
    public FakeLazyReference|ChildFixture $child;

    /** Same-type union, for building a cross-reference between two ParentFixture instances. */
    public FakeLazyReference|ParentFixture|null $sibling = null;

    /** @var array<int, mixed> */
    public array $items = [];

    /** Same union type, but private - exercises Reflection's visibility-agnostic fallbacks. */
    private FakeLazyReference|ChildFixture $secret;

    public FakeLazyReference|ChildFixture|null $optionalChild = null;

    public function setSecret(FakeLazyReference|ChildFixture $value): void
    {
        $this->secret = $value;
    }

    public function getSecret(): FakeLazyReference|ChildFixture
    {
        return $this->secret;
    }
}

final class ReadonlyParentFixture
{
    public function __construct(
        public readonly FakeLazyReference|ChildFixture $child,
    ) {
    }
}

// ---------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------

final class GraphVisitorTest extends TestCase
{
    private function skipUnlessSafeModeApiAvailable(): void
    {
        if (!method_exists(ObjectStorage::class, 'getStateHandler')
            || !method_exists(StateHandler::class, 'safeModeEnabled')
            || !method_exists(StateHandler::class, 'disableSafeMode')
        ) {
            self::markTestSkipped(
                'ObjectStorage::getStateHandler()->safeModeEnabled()/disableSafeMode() ' .
                'is not available under these exact names in this environment - adjust ' .
                'GraphVisitor and these tests to match the real API.'
            );
        }
    }

    public function testResolvesLazyReferenceInPublicProperty(): void
    {
        $child = new ChildFixture('foo');
        $parent = new ParentFixture();
        $parent->child = new FakeLazyReference($child);

        $visitor = new GraphVisitor(lazyReferenceClass: FakeLazyReference::class);
        $result = $visitor->resolve($parent);

        self::assertSame($parent, $result);
        self::assertSame($child, $parent->child);
        self::assertSame(1, $visitor->getResolvedCount());
        self::assertSame(0, $visitor->getDanglingCount());
    }

    public function testResolvesLazyReferenceInsideArray(): void
    {
        $grandchild = new GrandchildFixture();
        $parent = new ParentFixture();
        $parent->child = new ChildFixture();
        $parent->items = [1, 'two', new FakeLazyReference($grandchild)];

        $visitor = new GraphVisitor(lazyReferenceClass: FakeLazyReference::class);
        $visitor->resolve($parent);

        self::assertSame([1, 'two', $grandchild], $parent->items);
    }

    public function testResolvesRecursivelyIntoTheResolvedObjectToo(): void
    {
        $grandchild = new GrandchildFixture();

        $middle = new ChildFixture();
        $middle->child = new FakeLazyReference($grandchild);

        $root = new ParentFixture();
        $root->child = new FakeLazyReference($middle);

        $visitor = new GraphVisitor(lazyReferenceClass: FakeLazyReference::class);
        $visitor->resolve($root);

        self::assertSame($middle, $root->child);
        self::assertSame($grandchild, $root->child->child);
        self::assertSame(2, $visitor->getResolvedCount());
    }

    public function testResolvesLazyReferenceInPrivateProperty(): void
    {
        $child = new ChildFixture();
        $parent = new ParentFixture();
        $parent->child = new ChildFixture();
        $parent->setSecret(new FakeLazyReference($child));

        $visitor = new GraphVisitor(lazyReferenceClass: FakeLazyReference::class);
        $visitor->resolve($parent);

        self::assertSame($child, $parent->getSecret());
    }

    public function testResolvesDynamicallyAttachedProperty(): void
    {
        $child = new ChildFixture();
        $parent = new ParentFixture();
        $parent->child = new ChildFixture();
        $parent->extra = new FakeLazyReference($child); // not declared on the class

        $visitor = new GraphVisitor(lazyReferenceClass: FakeLazyReference::class);
        $visitor->resolve($parent);

        self::assertSame($child, $parent->extra);
    }

    public function testBreaksCircularReferencesInsteadOfLoopingForever(): void
    {
        $root = new ParentFixture();
        $root->child = new ChildFixture();

        $other = new ParentFixture();
        $other->child = new ChildFixture();
        $other->sibling = new FakeLazyReference($root); // points back to root
        $root->items = [new FakeLazyReference($other)];

        $visitor = new GraphVisitor(lazyReferenceClass: FakeLazyReference::class);
        $visitor->resolve($root); // must terminate

        self::assertSame($other, $root->items[0]);
        self::assertSame($root, $other->sibling);
        self::assertSame(2, $visitor->getResolvedCount());
    }

    public function testDanglingReferenceReturningNullIsLeftInPlaceByDefault(): void
    {
        $reference = new FakeLazyReference(null);
        $parent = new ParentFixture();
        $parent->child = $reference;

        $visitor = new GraphVisitor(lazyReferenceClass: FakeLazyReference::class);
        $visitor->resolve($parent);

        self::assertSame($reference, $parent->child);
        self::assertSame(1, $visitor->getDanglingCount());
        self::assertSame(0, $visitor->getResolvedCount());

        $entries = $visitor->getDanglingReferences();
        self::assertCount(1, $entries);
        self::assertSame($reference, $entries[0]['reference']);
        self::assertStringContainsString('getObject()', $entries[0]['reason']);
    }

    public function testDanglingReferenceThrowingIsCapturedWithReason(): void
    {
        $reference = new FakeLazyReference(new RuntimeException('uuid gone'));
        $parent = new ParentFixture();
        $parent->child = $reference;

        $visitor = new GraphVisitor(lazyReferenceClass: FakeLazyReference::class);
        $visitor->resolve($parent);

        self::assertSame($reference, $parent->child);

        $entries = $visitor->getDanglingReferences();
        self::assertStringContainsString('RuntimeException', $entries[0]['reason']);
        self::assertStringContainsString('uuid gone', $entries[0]['reason']);
    }

    public function testSameDanglingReferenceInstanceIsOnlyProbedOnce(): void
    {
        $reference = new FakeLazyReference(null);
        $parent = new ParentFixture();
        $parent->child = $reference;
        $parent->items = [$reference, $reference]; // same instance, 3 slots total

        $visitor = new GraphVisitor(lazyReferenceClass: FakeLazyReference::class);
        $visitor->resolve($parent);

        self::assertSame(1, $reference->calls);
        self::assertSame(1, $visitor->getDanglingCount());
    }

    public function testRemoveDanglingReferencesDeinitializesPublicProperty(): void
    {
        $reference = new FakeLazyReference(null);
        $parent = new ParentFixture();
        $parent->child = $reference;

        $visitor = new GraphVisitor(lazyReferenceClass: FakeLazyReference::class, removeDanglingReferences: true);
        $visitor->resolve($parent);

        // A real removal deinitializes the typed property - it does NOT
        // reset it to null (that's a different value, not "no value").
        self::assertFalse((new ReflectionProperty($parent, 'child'))->isInitialized($parent));
    }

    public function testRemoveDanglingReferencesDeinitializesPrivateUnionTypedProperty(): void
    {
        // Regression test: for a private property, isset() from outside
        // the class returns false (no __isset), so Reflection::unset()
        // falls through to its type-based default logic - which only
        // handles ReflectionNamedType and gives up (returns false, no
        // exception) for a union type like `FakeLazyReference|ChildFixture`
        // with no default and no null in the union. GraphVisitor must
        // fall back to a scope-bound raw unset() in that case.
        $reference = new FakeLazyReference(null);
        $parent = new ParentFixture();
        $parent->child = new ChildFixture();
        $parent->setSecret($reference);

        $visitor = new GraphVisitor(lazyReferenceClass: FakeLazyReference::class, removeDanglingReferences: true);
        $visitor->resolve($parent);

        self::assertFalse((new ReflectionProperty($parent, 'secret'))->isInitialized($parent));
    }

    public function testRemoveDanglingReferencesUnsetsArrayKeyEntirely(): void
    {
        $reference = new FakeLazyReference(null);
        $parent = new ParentFixture();
        $parent->child = new ChildFixture();
        $parent->items = ['a', $reference, 'c'];

        $visitor = new GraphVisitor(lazyReferenceClass: FakeLazyReference::class, removeDanglingReferences: true);
        $visitor->resolve($parent);

        self::assertSame([0 => 'a', 2 => 'c'], $parent->items);
        self::assertArrayNotHasKey(1, $parent->items);
    }

    public function testRemoveDanglingReferencesLeavesReadonlyPropertyInPlace(): void
    {
        $reference = new FakeLazyReference(null);
        $parent = new ReadonlyParentFixture($reference);

        $visitor = new GraphVisitor(lazyReferenceClass: FakeLazyReference::class, removeDanglingReferences: true);
        $visitor->resolve($parent);

        // A readonly property can't be deinitialized once set - best
        // effort gives up and leaves the dangling proxy rather than
        // throwing.
        self::assertSame($reference, $parent->child);
        self::assertSame(1, $visitor->getDanglingCount());
    }

    public function testMisconfiguredResolverMethodThrowsImmediatelyInsteadOfBeingTreatedAsDangling(): void
    {
        $parent = new ParentFixture();
        $parent->child = new FakeLazyReference(new ChildFixture());

        $visitor = new GraphVisitor(
            lazyReferenceClass: FakeLazyReference::class,
            resolverMethod: 'thisMethodDoesNotExist'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/thisMethodDoesNotExist/');

        $visitor->resolve($parent);
    }

    public function testShortNameFallbackDetectsProxyDespiteWrongConfiguredNamespace(): void
    {
        $child = new ChildFixture();
        $parent = new ParentFixture();
        $parent->child = new FakeLazyReference($child);

        // Deliberately wrong namespace, correct short class name - this
        // is exactly the failure mode that silently disabled resolution
        // when the assumed FQCN for the real library didn't match.
        $visitor = new GraphVisitor(lazyReferenceClass: 'Totally\\Wrong\\Namespace\\FakeLazyReference');
        $visitor->resolve($parent);

        self::assertSame($child, $parent->child);
    }

    public function testRootItselfAsLazyReferenceIsResolved(): void
    {
        $child = new ChildFixture();
        $root = new FakeLazyReference($child);

        $visitor = new GraphVisitor(lazyReferenceClass: FakeLazyReference::class);
        $result = $visitor->resolve($root);

        self::assertSame($child, $result);
    }

    public function testDanglingRootIsReturnedAsProxyRegardlessOfRemoveFlag(): void
    {
        $root = new FakeLazyReference(null);

        $visitor = new GraphVisitor(lazyReferenceClass: FakeLazyReference::class, removeDanglingReferences: true);
        $result = $visitor->resolve($root);

        self::assertSame($root, $result);
        self::assertSame(1, $visitor->getDanglingCount());
    }

    public function testSafeModeIsRestoredWhenTrippedDuringResolve(): void
    {
        $this->skipUnlessSafeModeApiAvailable();

        $callCount = 0;

        $stateHandler = $this->createMock(StateHandler::class);
        $stateHandler->expects(self::exactly(2))
            ->method('safeModeEnabled')
            ->willReturnCallback(static function () use (&$callCount): bool {
                $callCount++;
                return $callCount > 1; // false the first time (before), true the second (after)
            });
        $stateHandler->expects(self::once())->method('disableSafeMode');

        $storage = $this->createMock(ObjectStorage::class);
        $storage->method('getStateHandler')->willReturn($stateHandler);

        $parent = new ParentFixture();
        $parent->child = new FakeLazyReference(null); // triggers the walk; outcome irrelevant here

        $visitor = new GraphVisitor($storage, lazyReferenceClass: FakeLazyReference::class);
        $visitor->resolve($parent);

        self::assertTrue($visitor->wasSafeModeSuppressed());
    }

    public function testPreexistingSafeModeIsLeftUntouched(): void
    {
        $this->skipUnlessSafeModeApiAvailable();

        $stateHandler = $this->createMock(StateHandler::class);
        $stateHandler->expects(self::once())->method('safeModeEnabled')->willReturn(true); // already on
        $stateHandler->expects(self::never())->method('disableSafeMode');

        $storage = $this->createMock(ObjectStorage::class);
        $storage->method('getStateHandler')->willReturn($stateHandler);

        $parent = new ParentFixture();
        $parent->child = new ChildFixture();

        $visitor = new GraphVisitor($storage, lazyReferenceClass: FakeLazyReference::class);
        $visitor->resolve($parent);

        self::assertFalse($visitor->wasSafeModeSuppressed());
    }

    public function testRestoreSafeModeFalseNeverCallsDisableSafeMode(): void
    {
        $this->skipUnlessSafeModeApiAvailable();

        $stateHandler = $this->createMock(StateHandler::class);
        $stateHandler->expects(self::never())->method('safeModeEnabled');
        $stateHandler->expects(self::never())->method('disableSafeMode');

        $storage = $this->createMock(ObjectStorage::class);
        // restoreSafeMode: false short-circuits before getStateHandler() is
        // ever called - it's only stubbed here in case that assumption
        // changes.
        $storage->method('getStateHandler')->willReturn($stateHandler);

        $parent = new ParentFixture();
        $parent->child = new FakeLazyReference(null);

        $visitor = new GraphVisitor(
            $storage,
            lazyReferenceClass: FakeLazyReference::class,
            restoreSafeMode: false
        );
        $visitor->resolve($parent);

        self::assertFalse($visitor->wasSafeModeSuppressed());
    }
}