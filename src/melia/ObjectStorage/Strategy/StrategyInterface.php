<?php

namespace melia\ObjectStorage\Strategy;

use melia\ObjectStorage\Context\GraphBuilderContext;
use melia\ObjectStorage\Reflection\Reflection;
use melia\ObjectStorage\Strategy\Policy\ChildPersistence;
use melia\ObjectStorage\Strategy\Policy\PropertyPersistence;
use melia\ObjectStorage\UUID\Generator\AwareInterface;
use melia\ObjectStorage\Strategy\Policy\PropertyHydration;

interface StrategyInterface extends AwareInterface
{
    public const int DEFAULT_MAX_DEPTH = 512;
    public const int DEFAULT_SHARD_DEPTH = 2;
    public const int DEFAULT_POLICY_CHILD_PERSISTENCE = ChildPersistence::IF_NOT_EXIST;
    public const int DEFAULT_POLICY_PROPERTY_PERSISTENCE = PropertyPersistence::ALWAYS;
    public const int DEFAULT_POLICY_PROPERTY_HYDRATION = PropertyHydration::ALWAYS;

    public function inheritLifetime(?GraphBuilderContext $context = null): bool;

    public function getChecksumAlgorithm(): string;

    public function serialize(array $graph, int $depth): ?string;

    public function unserialize(string $data): array;

    public function getMaxDepth(): int;

    public function getShardDepth(): int;

    public function getPolicyChildPersistence(): int;

    public function checksumValidationEnabled(): bool;

    public function getPolicyPropertyPersistence() : int;

    public function getPolicyPropertyHydration(): int;

    /**
     * Determines whether a child node should be persisted in the graph-building process.
     *
     * @param GraphBuilderContext $context The context of the graph builder, containing relevant information for processing.
     * @param object $child The child object that is being assessed for persistence.
     * @param string $childUuid The unique identifier of the child.
     * @param bool $childExists Indicates whether the child already exists in the current graph.
     * @param array $path The path representing the hierarchy or location of the child in the graph.
     *
     * @return bool Returns true if the child should be persisted, otherwise false.
     */
    public function shouldPersistChild(
        GraphBuilderContext $context,
        object              $child,
        string              $childUuid,
        bool                $childExists,
        array               $path
    ): bool;

    /**
     * Determines whether a property should be persisted based on its reflection, name, and value.
     *
     * @param Reflection $reflection The reflection object providing metadata about the containing class or object.
     * @param string $propertyName The name of the property being assessed for persistence.
     * @param mixed $value The value of the property being evaluated.
     *
     * @return bool Returns true if the property should be persisted, otherwise false.
     */
    public function shouldPersistProperty(
        Reflection $reflection,
        string $propertyName,
        mixed $value
    ): bool;

    /**
     * Determines whether a property should be hydrated based on the given reflection, property name, and value.
     *
     * @param Reflection $reflection The reflection providing metadata about the class or object.
     * @param string $propertyName The name of the property being assessed for hydration.
     * @param mixed $value The value associated with the property to determine if hydration is necessary.
     *
     * @return bool Returns true if the property should be hydrated, otherwise false.
     */
    public function shouldHydrateProperty(
        Reflection $reflection,
        string $propertyName,
        mixed $value
    ): bool;

    /**
     * Hydrates a property of a given object by assigning a value through reflection.
     *
     * @param Reflection $reflection The reflection instance associated with the target object.
     * @param string $propertyName The name of the property to be hydrated.
     * @param mixed $value The value to assign to the property.
     *
     * @return void
     */
    public function hydrateProperty(
        Reflection $reflection,
        string $propertyName,
        mixed $value
    ) : void;
}