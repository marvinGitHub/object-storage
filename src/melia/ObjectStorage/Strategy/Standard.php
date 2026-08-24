<?php

namespace melia\ObjectStorage\Strategy;

use JsonException;
use melia\ObjectStorage\Checksum\AlgorithmAwareTrait;
use melia\ObjectStorage\Context\GraphBuilderContext;
use melia\ObjectStorage\Exception\DynamicPropertiesNotAllowedException;
use melia\ObjectStorage\Exception\InvalidPolicyException;
use melia\ObjectStorage\Exception\InvalidMaxDepthException;
use melia\ObjectStorage\Exception\TypeConversionFailureException;
use melia\ObjectStorage\Reflection\Reflection;
use melia\ObjectStorage\Strategy\Policy\ChildPersistence;
use melia\ObjectStorage\Strategy\Policy\PropertyPersistence;
use melia\ObjectStorage\Strategy\Policy\PropertyHydration;
use melia\ObjectStorage\UUID\Generator\AwareTrait as GeneratorAwareTrait;
use melia\ObjectStorage\UUID\Validator;
use ReflectionNamedType;

class Standard implements StrategyInterface
{
    use AlgorithmAwareTrait;
    use GeneratorAwareTrait;

    private bool $inheritLifetime = false;
    private int $maxDepth = self::DEFAULT_MAX_DEPTH;
    private int $shardDepth = self::DEFAULT_SHARD_DEPTH;
    private int $policyChildPersistence = StrategyInterface::DEFAULT_POLICY_CHILD_PERSISTENCE;
    private int $policyPropertyPersistence = StrategyInterface::DEFAULT_POLICY_PROPERTY_PERSISTENCE;

    private int $policyPropertyHydration = StrategyInterface::DEFAULT_POLICY_PROPERTY_HYDRATION;

    private bool $checksumValidation = true;

    public function enableLifetimeInheritance(): void
    {
        $this->inheritLifetime = true;
    }

    public function disableLifetimeInheritance(): void
    {
        $this->inheritLifetime = false;
    }

    public function inheritLifetime(?GraphBuilderContext $context = null): bool
    {
        return $this->inheritLifetime;
    }

    /**
     * @throws JsonException
     */
    public function serialize(array $graph, int $depth): ?string
    {
        return json_encode($graph, JSON_THROW_ON_ERROR | $depth, $depth) ?: null;
    }

    /**
     * @throws JsonException
     */
    public function unserialize(string $data): array
    {
        return json_decode($data, true, 512, JSON_THROW_ON_ERROR) ?: [];
    }

    /**
     * Retrieves the maximum allowable depth.
     *
     * @return int The current maximum depth value.
     */
    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    /**
     * Sets the maximum allowable depth.
     *
     * @param int $maxDepth The maximum depth value. Must be greater than 0.
     * @return void
     * @throws InvalidMaxDepthException If the provided max depth is less than or equal to 0.
     */
    public function setMaxDepth(int $maxDepth): void
    {
        if ($maxDepth <= 0) {
            throw new InvalidMaxDepthException('Max depth must be greater than 0.');
        }
        $this->maxDepth = $maxDepth;
    }

    /**
     * Retrieves the current shard depth.
     *
     * @return int The depth of the shard.
     */
    public function getShardDepth(): int
    {
        return $this->shardDepth;
    }

    /**
     * Sets the shard depth for the current instance.
     *
     * @param int $shardDepth The depth value to set. Must be between 0 and 32, inclusive.
     * @return void
     * @throws InvalidMaxDepthException If the provided shard depth is not within the allowed range.
     */
    public function setShardDepth(int $shardDepth): void
    {
        $maxShardDepth = Validator::UUID_LENGTH - VALIDATOR::UUID_HYPHENS_COUNT;

        if ($shardDepth <= 0 || $shardDepth > $maxShardDepth) {
            throw new InvalidMaxDepthException(sprintf('Shard depth must be between 0 and %u, inclusive.', $maxShardDepth));
        }
        $this->shardDepth = $shardDepth;
    }

    /**
     * Retrieves the current child write policy.
     *
     * @return int The current child write policy. Possible values include:
     *             - ChildPersistence::ALWAYS
     *             - ChildPersistence::IF_NOT_EXIST
     *             - ChildPersistence::NEVER
     */
    public function getPolicyChildPersistence(): int
    {
        return $this->policyChildPersistence;
    }

    /**
     * Sets the persistence policy for child objects.
     *
     * @param int $policyChildPersistence The child persistence policy. Must be one of the predefined constants:
     *                                    ChildPersistence::ALWAYS, ChildPersistence::IF_NOT_EXIST, ChildPersistence::NEVER, or ChildPersistence::CALLBACK.
     * @return void
     * @throws InvalidPolicyException Thrown if the provided persistence policy is invalid.
     */
    public function setPolicyChildPersistence(int $policyChildPersistence): void
    {
        if (!in_array($policyChildPersistence, [ChildPersistence::ALWAYS, ChildPersistence::IF_NOT_EXIST, ChildPersistence::NEVER, ChildPersistence::CALLBACK], true)) {
            throw new InvalidPolicyException('Invalid child persistence policy.');
        }
        $this->policyChildPersistence = $policyChildPersistence;
    }

    /**
     * Determines whether the given child object should be persisted.
     *
     * @param GraphBuilderContext $context The context of the graph building process.
     * @param object $child The child object being evaluated.
     * @param string $childUuid The UUID of the child object.
     * @param bool $childExists Indicates whether the child object already exists.
     * @param array $path The path within the graph to the current child object.
     * @return bool Returns true if the child object should be persisted, false otherwise.
     */
    public function shouldPersistChild(GraphBuilderContext $context, object $child, string $childUuid, bool $childExists, array $path): bool
    {
        if ($childExists) {
            return false;
        }
        return true;
    }

    public function checksumValidationEnabled(): bool
    {
        return $this->checksumValidation;
    }

    public function enableChecksumValidation(): void
    {
        $this->checksumValidation = true;
    }

    public function disableChecksumValidation(): void
    {
        $this->checksumValidation = false;
    }

    public function getPolicyPropertyPersistence(): int
    {
        return $this->policyPropertyPersistence;
    }

    /**
     * Sets the policy for static property persistence.
     *
     * @param int $policyPropertyPersistence The persistence policy value, which must be one of the defined constants:
     *                                             StaticPropertyPersistence::NEVER,
     *                                             StaticPropertyPersistence::CALLBACK,
     *                                             or StaticPropertyPersistence::ALWAYS.
     *
     * @return void
     *
     * @throws InvalidPolicyException If the provided policy value is not valid.
     */
    public function setPolicyPropertyPersistence(int $policyPropertyPersistence): void
    {
        if (!in_array($policyPropertyPersistence, [PropertyPersistence::CALLBACK, PropertyPersistence::ALWAYS], true)) {
            throw new InvalidPolicyException('Invalid static property persistence policy.');
        }
        $this->policyPropertyPersistence = $policyPropertyPersistence;
    }

    /**
     * Determines whether the specified property should be persisted.
     *
     * @param Reflection $reflection The reflection instance of the class or object being inspected.
     * @param string $propertyName The name of the property being evaluated.
     * @param mixed $value The current value of the property.
     * @return bool Returns true if the property should be persisted, false otherwise.
     */
    public function shouldPersistProperty(Reflection $reflection, string $propertyName, mixed $value): bool
    {
        return false;
    }

    /**
     * Sets the policy for property hydration.
     *
     * @param int $policyPropertyHydration The hydration policy to be set. Must be one of the allowed values defined in PropertyHydration.
     * @return void
     * @throws InvalidPolicyException If the given hydration policy is invalid.
     */
    public function setPolicyPropertyHydration(int $policyPropertyHydration): void
    {
        if (!in_array($policyPropertyHydration, [PropertyHydration::CALLBACK, PropertyHydration::ALWAYS], true)) {
            throw new InvalidPolicyException('Invalid virtual property hydration policy.');
        }
        $this->policyPropertyHydration = $policyPropertyHydration;
    }

    public function getPolicyPropertyHydration(): int
    {
        return $this->policyPropertyHydration;
    }

    /**
     * Determines whether a specific property should be hydrated.
     *
     * @param Reflection $reflection The reflection instance representing the class or object being inspected.
     * @param string $propertyName The name of the property being evaluated.
     * @param mixed $value The value of the property being considered for hydration.
     * @return bool Returns true if the property should be hydrated, false otherwise.
     */
    public function shouldHydrateProperty(Reflection $reflection, string $propertyName, mixed $value): bool
    {
        return false;
    }

    /**
     * Hydrates a property of an object with a given value.
     *
     * @param Reflection $reflection The reflection object used to access the property.
     * @param string $propertyName The name of the property to hydrate.
     * @param mixed $value The value to assign to the specified property.
     * @return void
     * @throws TypeConversionFailureException
     */
    public function hydrateProperty(Reflection $reflection, string $propertyName, mixed $value): void
    {
        $type = Reflection::getPropertyType($reflection->getTarget(), $propertyName);

        if ($type instanceof ReflectionNamedType) {
            /* type conversion of non-union types */
            $expectedType = $type->getName();
            $givenType = gettype($value);

            static $scalarMap = ['integer' => true, 'double' => true, 'boolean' => true, 'string' => true];

            if ($givenType !== $expectedType && isset($scalarMap[$givenType]) && false === settype($finalValue, $expectedType)) {
                throw new TypeConversionFailureException('Unable to convert value to type ' . $expectedType . ' for property ' . $propertyName . ' of class ' . $reflection->getClassname());
            }
        }

        $reflection->set($propertyName, $value);
    }
}