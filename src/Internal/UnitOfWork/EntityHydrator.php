<?php

/*
 * This file is part of the ModernORM package.
 *
 * (c) Andrey Bolonin <andreybolonin1989@gmail.com>
 * (c) Doctrine Project
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

use BackedEnum;
use Doctrine\Common\Collections\ArrayCollection;
use ModernORM\EntityManagerInterface;
use ModernORM\Query;
use ModernORM\UnitOfWork;
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

/** @internal Hydrates selected entities and associations; UnitOfWork owns identity selection and registration. */
final class EntityHydrator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UnitOfWork $unitOfWork,
        private readonly EntityStateManager $entityStateManager,
        private readonly IdentityMap $identityMap,
        private readonly EagerLoadCoordinator $eagerLoadCoordinator,
    ) {
    }

    /**
     * Hydrate only after UnitOfWork has selected/registered the entity. Snapshot changes are
     * applied immediately, preserving partial-assignment behavior when a later field fails.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $hints
     */
    public function hydrate(ClassMetadata $class, object $entity, array $data, array &$hints, ?object $managedEntity, string $idHash): void
    {
        // A refresh can target an unmanaged equivalent while snapshots belong to the canonical entity.
        $oid = spl_object_id($managedEntity ?? $entity);

        if (! isset($hints[FlatSqlHydrationPlan::HINT]) || $managedEntity !== null
            || ! $hints[FlatSqlHydrationPlan::HINT] instanceof FlatSqlHydrationPlan
            || ! $hints[FlatSqlHydrationPlan::HINT]->tryHydrate($class, $entity, $data)
        ) {
            foreach (self::hydrateScalars($class, $entity, $data) as $field => $value) {
                // Equivalent readonly values can retain the original object; snapshot that actual value.
                $this->entityStateManager->snapshots[$oid][$field] = $value;
            }
        }

        // Loading the entity right here removes it from the deferred queue.
        $this->eagerLoadCoordinator->forgetEntity($class->name, $idHash);

        foreach ($class->associationMappings as $field => $assoc) {
            // Check if the association is not among the fetch-joined associations already.
            if (isset($hints['fetchAlias'], $hints['fetched'][$hints['fetchAlias']][$field])) {
                continue;
            }

            if (! isset($hints['fetchMode'][$class->name][$field])) {
                $hints['fetchMode'][$class->name][$field] = $assoc->fetch;
            }

            $targetClass = $this->em->getClassMetadata($assoc->targetEntity);

            switch (true) {
                case $assoc->isToOne():
                    if (! $assoc->isOwningSide()) {
                        // use the given entity association
                        if (isset($data[$field]) && is_object($data[$field]) && isset($this->entityStateManager->states[spl_object_id($data[$field])])) {
                            $this->entityStateManager->snapshots[$oid][$field] = $data[$field];

                            $class->propertyAccessors[$field]->setValue($entity, $data[$field]);
                            $this->unitOfWork->hydrateToOneAssociation($data[$field], $assoc->mappedBy, $entity, $this->unitOfWork->isHydrationRefresh($data[$field], $hints));

                            continue 2;
                        }

                        // Inverse side of x-to-one can never be lazy
                        $class->propertyAccessors[$field]->setValue($entity, $this->unitOfWork->getEntityPersister($assoc->targetEntity)->loadOneToOneEntity($assoc, $entity));

                        continue 2;
                    }

                    // use the entity association
                    if (isset($data[$field]) && is_object($data[$field]) && isset($this->entityStateManager->states[spl_object_id($data[$field])])) {
                        $class->propertyAccessors[$field]->setValue($entity, $data[$field]);
                        $this->entityStateManager->snapshots[$oid][$field] = $data[$field];

                        break;
                    }

                    assert($assoc->isToOneOwningSide());
                    $associatedId = self::toOneIdentifier($assoc, $targetClass, $data);
                    $relatedIdHash = '';
                    $managedAssociation = null;
                    // Preserve lookup ordering: NULL/SoftDelete targets bypass the raw Identity Map path.
                    if ($associatedId !== [] && $targetClass->softDeleteField === null) {
                        $relatedIdHash = UnitOfWork::getIdHashByIdentifier($associatedId);
                        $managedAssociation = $this->identityMap->get($targetClass->name, $relatedIdHash);
                    }
                    $mode = self::planToOneLoad(
                        $targetClass,
                        $associatedId,
                        $managedAssociation,
                        $hints['fetchMode'][$class->name][$field],
                        $hints[UnitOfWork::HINT_DEFEREAGERLOAD] ?? null,
                        $managedAssociation !== null
                            && $hints['fetchMode'][$class->name][$field] === ClassMetadata::FETCH_EAGER
                            && isset($hints[UnitOfWork::HINT_DEFEREAGERLOAD])
                            && $this->unitOfWork->isUninitializedObject($managedAssociation),
                    );

                    switch ($mode) {
                        case ToOneHydrationMode::NULL_REFERENCE:
                            $class->propertyAccessors[$field]->setValue($entity, null);
                            $this->entityStateManager->snapshots[$oid][$field] = null;
                            break 2;

                        case ToOneHydrationMode::SOFT_DELETE_LOOKUP:
                            $newValue = $this->em->find($targetClass->name, $associatedId);
                            $class->propertyAccessors[$field]->setValue($entity, $newValue);
                            $this->entityStateManager->snapshots[$oid][$field] = $newValue;
                            break 2;

                        case ToOneHydrationMode::MANAGED:
                        case ToOneHydrationMode::MANAGED_DEFERRED:
                            assert($managedAssociation !== null);
                            $newValue = $managedAssociation;
                            if ($mode === ToOneHydrationMode::MANAGED_DEFERRED) {
                                $this->eagerLoadCoordinator->scheduleEntity($targetClass->name, $relatedIdHash, current($associatedId));
                            }
                            break;

                        case ToOneHydrationMode::REFERENCE:
                        case ToOneHydrationMode::REFERENCE_DEFERRED:
                            if ($mode === ToOneHydrationMode::REFERENCE_DEFERRED) {
                                $this->eagerLoadCoordinator->scheduleEntity($targetClass->name, $relatedIdHash, current($associatedId));
                            }
                            $newValue = $this->em->getProxyFactory()->getProxy($assoc->targetEntity, $associatedId);
                            $this->unitOfWork->registerManagedProxy($newValue, $associatedId);
                            break;

                        case ToOneHydrationMode::EAGER_LOOKUP:
                            $newValue = $this->em->find($assoc->targetEntity, $associatedId);
                            break;
                    }

                    if ($assoc->isManyToOne()
                        && $hints['fetchMode'][$class->name][$field] !== ClassMetadata::FETCH_EAGER
                        && $newValue !== null) {
                        $this->em->getProxyFactory()->getBatchQueue()->add(
                            $newValue,
                            $targetClass,
                            $associatedId,
                            $assoc->batchSize,
                        );
                    }

                    $this->entityStateManager->snapshots[$oid][$field] = $newValue;
                    $class->propertyAccessors[$field]->setValue($entity, $newValue);

                    if ($assoc->inversedBy !== null && $assoc->isOneToOne() && $newValue !== null) {
                        $inverseAssoc = $targetClass->associationMappings[$assoc->inversedBy];
                        $this->unitOfWork->hydrateToOneAssociation($newValue, $inverseAssoc->fieldName, $entity, $this->unitOfWork->isHydrationRefresh($newValue, $hints));
                    }

                    break;

                default:
                    assert($assoc->isToMany());
                    // use the given collection
                    if (isset($data[$field]) && $data[$field] instanceof PersistentCollection) {
                        self::hydrateToMany($this->em, $targetClass, $entity, $assoc, $class->propertyAccessors[$field], $data[$field]);
                        $this->entityStateManager->snapshots[$oid][$field] = $data[$field];

                        break;
                    }

                    $pColl = self::hydrateToMany($this->em, $targetClass, $entity, $assoc, $class->propertyAccessors[$field]);

                    if ($hints['fetchMode'][$class->name][$field] === ClassMetadata::FETCH_EAGER) {
                        if (
                            $assoc->isOneToMany()
                            // is iteration
                            && ! (isset($hints[Query::HINT_INTERNAL_ITERATION]) && $hints[Query::HINT_INTERNAL_ITERATION])
                            // is foreign key composite
                            && ! ($targetClass->hasAssociation($assoc->mappedBy) && count($targetClass->getAssociationMapping($assoc->mappedBy)->joinColumns) > 1)
                            && ! $assoc->isIndexed()
                        ) {
                            $this->eagerLoadCoordinator->scheduleCollection($pColl, $class);
                        } else {
                            $this->unitOfWork->loadCollection($pColl);
                            $pColl->takeSnapshot();
                        }
                    }

                    if ($hints['fetchMode'][$class->name][$field] !== ClassMetadata::FETCH_EAGER) {
                        $this->unitOfWork->getLazyCollectionQueue()->add($pColl);
                    }

                    $this->entityStateManager->snapshots[$oid][$field] = $pColl;
                    break;
            }
        }
    }

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
            && version_compare(phpversion('deepclone'), '0.8.13', '>=')
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
