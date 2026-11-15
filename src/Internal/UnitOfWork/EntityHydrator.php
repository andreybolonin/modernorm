<?php

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

use BackedEnum;
use Generator;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\ToOneOwningSideMapping;
use ModernORM\Mapping\PropertyAccessors\ReadonlyAccessor;

/** @internal Assigns scalar fields and plans owning to-one loading without managing ORM state. */
final class EntityHydrator
{
    /**
     * Consume the returned generator to hydrate fields in input order.
     * Yield retained readonly values so the coordinator can update its snapshot immediately,
     * including when a later field assignment fails.
     *
     * @param array<string, mixed> $data
     * @return Generator<string, mixed>
     */
    public static function hydrateScalars(ClassMetadata $metadata, object $entity, array $data): Generator
    {
        foreach ($data as $field => $value) {
            if (! isset($metadata->fieldMappings[$field])) {
                continue;
            }
            $accessor = $metadata->propertyAccessors[$field];
            $accessor->setValue($entity, $value);
            if ($accessor instanceof ReadonlyAccessor) {
                yield $field => $accessor->getValue($entity);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function toOneIdentifier(ToOneOwningSideMapping $association, ClassMetadata $target, array $data): array
    {
        $identifier = [];
        foreach ($association->targetToSourceKeyColumns as $column => $sourceColumn) {
            $value = $data[$sourceColumn] ?? null;
            if ($value !== null) {
                $identifier[$target->fieldNames[$column]] = $value instanceof BackedEnum ? $value->value : $value;
            } elseif (in_array($target->getFieldForColumn($column), $target->identifier, true)) {
                return [];
            }
        }
        return $identifier;
    }

    /**
     * The deferred hint retains its historical presence/truthiness distinction:
     * existing eager proxies are queued when the hint is present, new proxies only when enabled.
     *
     * @param array<string, mixed> $identifier
     */
    public static function planToOneLoad(
        ClassMetadata $target,
        array $identifier,
        ?object $managedTarget,
        int $fetchMode,
        mixed $deferEagerLoad,
        bool $targetIsUninitialized,
    ): ToOneHydrationMode {
        if ($identifier === []) {
            return ToOneHydrationMode::NULL_REFERENCE;
        }
        if ($target->softDeleteField !== null) {
            return ToOneHydrationMode::SOFT_DELETE_LOOKUP;
        }
        if ($managedTarget !== null) {
            return $fetchMode === ClassMetadata::FETCH_EAGER && $deferEagerLoad !== null && $targetIsUninitialized
                ? ToOneHydrationMode::MANAGED_DEFERRED
                : ToOneHydrationMode::MANAGED;
        }
        if ($fetchMode !== ClassMetadata::FETCH_EAGER) {
            return ToOneHydrationMode::REFERENCE;
        }
        return $deferEagerLoad ? ToOneHydrationMode::REFERENCE_DEFERRED : ToOneHydrationMode::EAGER_LOOKUP;
    }

}
