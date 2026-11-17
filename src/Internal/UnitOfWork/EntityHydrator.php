<?php

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

use BackedEnum;
use Doctrine\Common\Collections\ArrayCollection;
use ModernORM\EntityManagerInterface;
use ModernORM\PersistentCollection;
use ModernORM\Mapping\AssociationMapping;
use ModernORM\Mapping\ToManyAssociationMapping;
use Generator;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\ToOneOwningSideMapping;
use ModernORM\Mapping\PropertyAccessors\PropertyAccessor;
use ModernORM\Mapping\PropertyAccessors\EnumPropertyAccessor;
use ModernORM\Mapping\PropertyAccessors\RawValuePropertyAccessor;
use ModernORM\Mapping\PropertyAccessors\ReadonlyAccessor;
use ModernORM\Mapping\PropertyAccessors\TypedNoDefaultPropertyAccessor;
use ReflectionNamedType;
use ReflectionEnum;
use WeakMap;

/** @internal Assigns scalar fields/to-many collections and plans owning to-one loading without managing ORM state. */
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
        static $native = extension_loaded('deepclone')
            && version_compare(phpversion('deepclone'), '0.8.11', '>=')
            && function_exists('deepclone_hydrate')
            && defined('DEEPCLONE_HYDRATE_NO_LAZY_INIT');

        if ($native) {
            if ($metadata->embeddedClasses === []) {
                if (! $metadata->reflClass?->isUninitializedLazyObject($entity) && self::tryHydrateWithDeepclone($metadata, $entity, $data)) {
                    return;
                }
            } elseif (count($metadata->fieldMappings) >= EmbeddedHydrator::MIN_BATCH_SIZE && ! $metadata->reflClass?->isUninitializedLazyObject($entity)) {
                $plan = EmbeddedHydrator::plan($metadata);
                if ($plan !== []) {
                    yield from EmbeddedHydrator::hydrate($metadata, $entity, $data, $plan);
                    return;
                }
            }
        }

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

    /** @param array<string, mixed> $data */
    private static function tryHydrateWithDeepclone(ClassMetadata $metadata, object $entity, array $data): bool
    {
        /** @var WeakMap<ClassMetadata, array{accessors: array<string, PropertyAccessor>, keys: array<string, string>, required: array<string, true>, enums: array<string, string>}> $plans */
        static $plans = new WeakMap();

        // Runtime accessors are replaceable and rebuilt after metadata deserialization.
        $plan = $plans[$metadata] ?? null;
        if ($plan === null || $plan['accessors'] !== $metadata->propertyAccessors) {
            $keys = $required = $enums = [];
            foreach ($metadata->fieldMappings as $field => $mapping) {
                $accessor = $metadata->propertyAccessors[$field];
                if (! in_array($accessor::class, [RawValuePropertyAccessor::class, TypedNoDefaultPropertyAccessor::class, EnumPropertyAccessor::class], true)) {
                    $keys = [];
                    break;
                }
                $property = $accessor->getUnderlyingReflector();
                $type = $property->getType();
                if ($accessor::class === EnumPropertyAccessor::class
                    && $type instanceof ReflectionNamedType
                    && $type->getName() === $mapping->enumType
                ) {
                    $enums[$field] = (new ReflectionEnum($mapping->enumType))->getBackingType()->getName();
                } elseif ($accessor::class !== RawValuePropertyAccessor::class && $accessor::class !== TypedNoDefaultPropertyAccessor::class) {
                    $keys = [];
                    break;
                } elseif ($type instanceof ReflectionNamedType && enum_exists($type->getName())) {
                    // An enum without its normal ORM conversion accessor is not a native candidate.
                    $keys = [];
                    break;
                }
                if ($property->isReadOnly() || $property->isVirtual()) {
                    $keys = [];
                    break;
                }
                if ($type !== null && ! $type->allowsNull()) {
                    $required[$field] = true;
                }
                $keys[$field] = $property->isPrivate()
                    ? "\0" . $property->getDeclaringClass()->getName() . "\0" . $property->getName()
                    : ($property->isProtected() ? "\0*\0" . $property->getName() : $property->getName());
            }
            $plans[$metadata] = $plan = ['accessors' => $metadata->propertyAccessors, 'keys' => $keys, 'required' => $required, 'enums' => $enums];
        }

        $keys = $plan['keys'];
        if ($keys === []) {
            return false;
        }
        $required = $plan['required'];
        $enums = $plan['enums'];
        $vars = [];
        foreach ($data as $field => $value) {
            if (! isset($keys[$field])) {
                continue;
            }
            // Decide before writing anything, so fallbacks preserve assignment/error order.
            if (($value === null && isset($required[$field]))
                || ($value !== null && isset($enums[$field]) && ! is_object($value) && get_debug_type($value) !== $enums[$field])
            ) {
                return false;
            }
            $vars[$keys[$field]] = $value;
        }
        if ($vars !== []) {
            \deepclone_hydrate($entity, $vars, DEEPCLONE_HYDRATE_NO_LAZY_INIT);
        }
        return true;
    }

    /**
     * Only prepares and assigns the collection; no SQL, queue or snapshot operations.
     * Supplied collections retain initialization, contents, dirty state and snapshots.
     */
    public static function hydrateToMany(
        EntityManagerInterface $em,
        ClassMetadata $target,
        object $owner,
        AssociationMapping&ToManyAssociationMapping $association,
        PropertyAccessor $accessor,
        ?PersistentCollection $supplied = null,
    ): PersistentCollection {
        $collection = $supplied ?? new PersistentCollection($em, $target, new ArrayCollection());
        $collection->setOwner($owner, $association);
        if ($supplied === null) {
            $collection->setInitialized(false);
        }
        $accessor->setValue($owner, $collection);
        return $collection;
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
