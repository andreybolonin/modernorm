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

use Closure;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use ModernORM\EntityManager;
use ModernORM\EntityManagerInterface;
use ModernORM\Event\ListenersInvoker;
use ModernORM\Event\PreFlushEventArgs;
use ModernORM\Events;
use ModernORM\Exception\ORMInvalidArgumentException;
use ModernORM\Exception\ORMException;
use ModernORM\Exception\UnexpectedAssociationValue;
use ModernORM\Mapping\AssociationMapping;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\PropertyAccessors\RawValuePropertyAccessor;
use ModernORM\Mapping\PropertyAccessors\TypedNoDefaultPropertyAccessor;
use ModernORM\PersistentCollection;
use ModernORM\UnitOfWork;
use RuntimeException;

/** @internal Owns dirty-check scheduling, change sets and association reachability detection. */
final class ChangeTrackingCoordinator
{
    /**
     * Map of entity changes. Keys are object ids (spl_object_id).
     * Filled at the beginning of a commit of the UnitOfWork and cleaned at the end.
     *
     * @phpstan-var array<int, array<string, array{mixed, mixed}>>
     */
    private array $entityChangeSets = [];

    /**
     * Map of entities that are scheduled for dirty checking at commit time.
     * This is only used for entities with a change tracking policy of DEFERRED_EXPLICIT.
     * Keys are object ids (spl_object_id).
     *
     * @var array<class-string, array<int, mixed>>
     */
    private array $scheduledForSynchronization = [];

    /**
     * New entities that were discovered through relationships that were not
     * marked as cascade-persist. During flush, this array is populated and
     * then pruned of any entities that were discovered through a valid
     * cascade-persist path. (Leftovers cause an error.)
     *
     * Keys are OIDs, payload is a two-item array describing the association
     * and the entity.
     *
     * @var array<int, array{AssociationMapping, object}> indexed by respective object spl_object_id()
     */
    private array $nonCascadedNewDetectedEntities = [];

    /**
     * Read-Only objects are never evaluated
     *
     * @var array<int, true>
     */
    private array $readOnlyObjects = [];

    /** @param Closure(ClassMetadata, object): void $persistNew */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UnitOfWork $unitOfWork,
        private readonly EntityStateManager $entityStateManager,
        private readonly IdentityMap $identityMap,
        private readonly FlushOperationQueues $flushQueues,
        private readonly LazyReferenceTracker $lazyReferenceTracker,
        private readonly AssociationChangePlanner $associationChangePlanner,
        private readonly ListenersInvoker $listenersInvoker,
        private readonly Closure $persistNew,
    ) {
    }

    /** @param array<string, array{mixed, mixed}>|null $changeset Null removes the entry. */
    public function replaceChangeSet(int $oid, ?array $changeset): void
    {
        if ($changeset === null) {
            unset($this->entityChangeSets[$oid]);
        } else {
            $this->entityChangeSets[$oid] = $changeset;
        }
    }

    public function markReadOnly(object $entity): void
    {
        $this->readOnlyObjects[spl_object_id($entity)] = true;
    }

    public function isMarkedReadOnly(object $entity): bool
    {
        return isset($this->readOnlyObjects[spl_object_id($entity)]);
    }

    public function forgetReadOnly(int $oid): void
    {
        unset($this->readOnlyObjects[$oid]);
    }

    public function clearReadOnlyMarks(): void
    {
        $this->readOnlyObjects = [];
    }

    public function clearPendingChecks(): void
    {
        $this->nonCascadedNewDetectedEntities = [];
        $this->scheduledForSynchronization = [];
    }

    public function clearChangeSets(): void
    {
        $this->entityChangeSets = [];
    }

    /** Completed-flush cleanup does not remove entity-level read-only marks. */
    public function clearAfterFlush(): void
    {
        $this->scheduledForSynchronization = [];
        $this->entityChangeSets = [];
        $this->nonCascadedNewDetectedEntities = [];
    }

    /**
     * Computes the changesets of all entities scheduled for insertion.
     */
    private function computeScheduleInsertsChangeSets(): void
    {
        foreach ($this->flushQueues->insertions() as $entity) {
            $class = $this->em->getClassMetadata($entity::class);

            if ($class->reflClass->isUninitializedLazyObject($entity)) {
                $class->reflClass->initializeLazyObject($entity);
            }

            $this->unitOfWork->computeChangeSet($class, $entity);
        }
    }

    /**
     * Gets the changeset for an entity.
     *
     * @return mixed[][]
     * @phpstan-return array<string, array{mixed, mixed}|PersistentCollection>
     */
    public function & getEntityChangeSet(object $entity): array
    {
        $oid  = spl_object_id($entity);
        $data = [];

        if (! isset($this->entityChangeSets[$oid])) {
            return $data;
        }

        return $this->entityChangeSets[$oid];
    }

    /**
     * Computes the changes that happened to a single entity.
     *
     * Modifies/populates the following properties:
     *
     * the original snapshot
     * If the entity is NEW or MANAGED but not yet fully persisted (only has an id)
     * then it was not fetched from the database and therefore we have no original
     * entity data yet. All of the current entity data is stored as the original entity data.
     *
     * {@link _entityChangeSets}
     * The changes detected on all properties of the entity are stored there.
     * A change is a tuple array where the first entry is the old value and the second
     * entry is the new value of the property. Changesets are used by persisters
     * to INSERT/UPDATE the persistent entity state.
     *
     * Scheduled entity updates
     * If the entity is already fully MANAGED (has been fetched from the database before)
     * and any changes to its properties are detected, then a reference to the entity is stored
     * there to mark it for an update.
     *
     * Scheduled collection deletions
     * If a PersistentCollection has been de-referenced in a fully MANAGED entity,
     * then this collection is marked for deletion.
     *
     * @param ClassMetadata $class  The class descriptor of the entity.
     * @param object        $entity The entity for which to compute the changes.
     * @phpstan-param ClassMetadata<T> $class
     * @phpstan-param T $entity
     *
     * @template T of object
     *
     * @ignore
     */
    public function computeChangeSet(ClassMetadata $class, object $entity): void
    {
        $oid = spl_object_id($entity);

        if ($class->isReadOnly && ! $this->flushQueues->hasInsertion($oid)) {
            if (! $this->unitOfWork->isUninitializedObject($entity)) {
                $this->computeReadOnlyAssociationChanges($class, $entity);
            }
            return;
        }

        if (isset($this->readOnlyObjects[$oid])) {
            return;
        }

        $invoke = $this->listenersInvoker->getSubscribedSystems($class, Events::preFlush) & ~ListenersInvoker::INVOKE_MANAGER;

        if ($invoke !== ListenersInvoker::INVOKE_NONE) {
            $this->listenersInvoker->invoke($class, Events::preFlush, $entity, new PreFlushEventArgs($this->em), $invoke);
        }

        if ($class->associationMappings === []
            && ($this->entityStateManager->states[$oid] ?? null) === UnitOfWork::STATE_MANAGED
            && isset($this->entityStateManager->snapshots[$oid])
            && ! $this->flushQueues->hasInsertion($oid)
            && ! $this->lazyReferenceTracker->contains($entity)
        ) {
            $plan = ScalarChangeTrackingPlan::forMetadata($class);
            $result = $plan?->compare($class, $entity, $this->entityStateManager->snapshots[$oid]);
            if ($result !== null) {
                if ($result !== []) {
                    [$changeSet, $actualData] = $result;
                    $this->entityChangeSets[$oid] = $changeSet;
                    $this->entityStateManager->snapshots[$oid] = $actualData;
                    $this->flushQueues->addUpdate($entity);
                }
                return;
            }
        }

        $actualData = [];
        $inlineInsert = ! isset($this->entityStateManager->snapshots[$oid]) && $this->flushQueues->hasInsertion($oid);
        $changeSet = [];

        foreach ($class->propertyAccessors as $name => $refProp) {
            $value = $refProp->getValue($entity);

            if ($class->isCollectionValuedAssociation($name) && $value !== null) {
                if ($value instanceof PersistentCollection) {
                    if ($value->getOwner() === $entity) {
                        $actualData[$name] = $value;
                        continue;
                    }

                    $value = new ArrayCollection($value->getValues());
                }

                // If $value is not a Collection then use an ArrayCollection.
                if (! $value instanceof Collection) {
                    $value = new ArrayCollection($value);
                }

                $assoc = $class->associationMappings[$name];
                assert($assoc->isToMany());

                // Inject PersistentCollection
                $value = new PersistentCollection(
                    $this->em,
                    $this->em->getClassMetadata($assoc->targetEntity),
                    $value,
                );
                $value->setOwner($entity, $assoc);
                $value->setDirty(! $value->isEmpty());

                $refProp->setValue($entity, $value);

                $actualData[$name] = $value;

                continue;
            }

            if ((! $class->isIdentifier($name) || ! $class->isIdGeneratorIdentity()) && ($name !== $class->versionField)) {
                $actualData[$name] = $value;
                if ($inlineInsert && (! isset($class->associationMappings[$name]) || $class->associationMappings[$name]->isToOneOwningSide())) {
                    $changeSet[$name] = [null, $value];
                }
            }
        }

        if (! isset($this->entityStateManager->snapshots[$oid])) {
            // Entity is either NEW or MANAGED but not yet fully persisted (only has an id).
            // These result in an INSERT.
            $this->entityStateManager->snapshots[$oid] = $actualData;
            if (! $inlineInsert) {
                foreach ($actualData as $propName => $actualValue) {
                    if (! isset($class->associationMappings[$propName])) {
                        $changeSet[$propName] = [null, $actualValue];

                        continue;
                    }

                    $assoc = $class->associationMappings[$propName];

                    if ($assoc->isToOneOwningSide()) {
                        $changeSet[$propName] = [null, $actualValue];
                    }
                }
            }

            $this->entityChangeSets[$oid] = $changeSet;
        } else {
            // Entity is "fully" MANAGED: it was already fully persisted before
            // and we have a copy of the original data
            $originalData  = $this->entityStateManager->snapshots[$oid];
            $trackedReference = $this->lazyReferenceTracker->contains($entity);
            $changeSet     = [];

            if ($trackedReference && ! $this->unitOfWork->isUninitializedObject($entity)) {
                $this->reconcileCompletedReference($oid, $class, $actualData, $originalData, $changeSet);
                $this->lazyReferenceTracker->forget($entity);
            }

            $scalarChanges = ChangeSetComputer::compare($class, $originalData, $actualData);
            foreach ($actualData as $propName => $actualValue) {
                if (! isset($class->associationMappings[$propName])) {
                    if (isset($scalarChanges[$propName])) {
                        $changeSet[$propName] = $scalarChanges[$propName];
                    }
                    continue;
                }

                if (! array_key_exists($propName, $originalData)) {
                    continue;
                }
                $orgValue = $originalData[$propName];

                if ($orgValue === $actualValue) {
                    continue;
                }

                $assoc = $class->associationMappings[$propName];

                // Persistent collection was exchanged with the "originally"
                // created one. This can only mean it was cloned and replaced
                // on another entity.
                if ($actualValue instanceof PersistentCollection) {
                    assert($assoc->isToMany());
                    $owner = $actualValue->getOwner();
                    if ($owner === null) { // cloned
                        $actualValue->setOwner($entity, $assoc);
                    } elseif ($owner !== $entity) { // no clone, we have to fix
                        if (! $actualValue->isInitialized()) {
                            $actualValue->initialize(); // we have to do this otherwise the cols share state
                        }

                        $newValue = clone $actualValue;
                        $newValue->setOwner($entity, $assoc);
                        $class->propertyAccessors[$propName]->setValue($entity, $newValue);
                    }
                }

                if ($orgValue instanceof PersistentCollection) {
                    if ($assoc->isOneToMany() && $assoc->orphanRemoval) {
                        // Replacing a container does not orphan members retained in the association.
                        $this->scheduleCollectionReplacement($orgValue, $actualValue ?? []);
                        $changeSet[$propName] = $orgValue;
                        continue;
                    }

                    // A PersistentCollection was de-referenced, so delete it.
                    $coid = spl_object_id($orgValue);

                    if ($this->flushQueues->hasCollectionDeletion($coid)) {
                        continue;
                    }

                    $this->flushQueues->addCollectionDeletion($orgValue);
                    $changeSet[$propName]             = $orgValue; // Signal changeset, to-many assocs will be ignored.

                    continue;
                }

                if ($assoc->isToOne()) {
                    if ($assoc->isOwningSide()) {
                        $changeSet[$propName] = [$orgValue, $actualValue];
                    }

                    if ($orgValue !== null && $assoc->orphanRemoval) {
                        assert(is_object($orgValue));
                        $this->associationChangePlanner->schedule($orgValue, OrphanRemovalReason::TO_ONE_REPLACEMENT);
                    }
                }
            }

            if ($changeSet) {
                $this->entityChangeSets[$oid]   = $changeSet;
                $this->entityStateManager->snapshots[$oid] = $actualData;
                $this->flushQueues->addUpdate($entity);
            }
        }

        // Look for changes in associations of the entity
        foreach ($class->associationMappings as $field => $assoc) {
            $val = $class->propertyAccessors[$field]->getValue($entity);
            if ($val === null) {
                continue;
            }

            $this->computeAssociationChanges($assoc, $val);

            if (
                ! isset($this->entityChangeSets[$oid]) &&
                $assoc->isManyToManyOwningSide() &&
                $val instanceof PersistentCollection &&
                $val->isDirty()
            ) {
                $this->entityChangeSets[$oid]   = [];
                $this->entityStateManager->snapshots[$oid] = $actualData;
                $this->flushQueues->addUpdate($entity);
            }
        }
    }

    /** @param iterable<object> $current */
    private function scheduleCollectionReplacement(PersistentCollection $original, iterable $current): void
    {
        $mapping = $original->getMapping();
        if ($mapping->isOneToMany() && $mapping->orphanRemoval) {
            // Read current membership first, matching the previous collection-diff traversal order.
            $currentMembers = is_array($current) ? $current : iterator_to_array($current);
            $originalMembers = $original->toArray();
            foreach (AssociationChangePlanner::removedElements($originalMembers, $currentMembers) as $removed) {
                $this->associationChangePlanner->schedule($removed, OrphanRemovalReason::COLLECTION_REPLACEMENT, $original);
            }
            return;
        }
        $this->flushQueues->addCollectionDeletion($original);
    }

    /** Process associations without reading/comparing scalar or embedded entity fields. */
    private function computeReadOnlyAssociationChanges(ClassMetadata $class, object $entity): void
    {
        $oid = spl_object_id($entity);
        foreach ($class->associationMappings as $field => $association) {
            $value = $class->propertyAccessors[$field]->getValue($entity);
            if ($association->isToMany()) {
                $original = $this->entityStateManager->snapshots[$oid][$field] ?? null;
                if ($value !== null && (! $value instanceof PersistentCollection || $value->getOwner() !== $entity)) {
                    if ($value instanceof PersistentCollection) {
                        $value = new ArrayCollection($value->getValues());
                    } elseif (! $value instanceof Collection) {
                        $value = new ArrayCollection($value);
                    }
                    $value = new PersistentCollection($this->em, $this->em->getClassMetadata($association->targetEntity), $value);
                    $value->setOwner($entity, $association);
                    $value->setDirty(! $value->isEmpty());
                    $class->propertyAccessors[$field]->setValue($entity, $value);
                }
                if ($original instanceof PersistentCollection && $original !== $value) {
                    $this->scheduleCollectionReplacement($original, $value ?? []);
                }
                $this->entityStateManager->snapshots[$oid][$field] = $value;
            }
            if ($value !== null) {
                $this->computeAssociationChanges($association, $value);
            }
        }
    }

    /**
     * A native lazy association proxy is registered with an empty data
     * snapshot (see registerManaged() callers below) until it is fully
     * hydrated through UnitOfWork::createEntity(). But PHP can complete
     * such an object's initialization without ever going through that
     * method — e.g. hydrating the owning side of a to-one association
     * assigns the inverse-side property directly onto the still-lazy
     * proxy, and if that happens to be the proxy's only remaining
     * property, PHP marks it fully initialized right there. When that
     * happens this entity is no longer "uninitialized" per
     * isUninitializedObject(), yet originalEntityData was never
     * populated, so every field would be silently and permanently
     * treated as "a partially omitted one" and skipped forever. We have
     * no reliable prior value for any of these fields, so force them
     * into this change set when updatable, to make sure the entity's
     * current writable values reach the database, then adopt them as the baseline for
     * future comparisons.
     *
     * @param ClassMetadata<object> $class
     * @param array<string, mixed>  $actualData
     * @param array<string, mixed>  $originalData
     * @param array<string, mixed>  $changeSet
     */
    private function reconcileCompletedReference(
        int $oid,
        ClassMetadata $class,
        array $actualData,
        array &$originalData,
        array &$changeSet,
    ): void {
        foreach ($actualData as $propName => $actualValue) {
            if (
                ! isset($class->fieldMappings[$propName]->notUpdatable)
                && (! isset($class->associationMappings[$propName])
                    || $class->associationMappings[$propName]->isToOneOwningSide())
            ) {
                $changeSet[$propName] = [null, $actualValue];
            }

            $originalData[$propName] = $actualValue;
        }

        $this->entityStateManager->snapshots[$oid] = $originalData;
    }

    /**
     * Computes all the changes that have been done to entities and collections
     * since the last commit and stores these changes in the _entityChangeSet map
     * temporarily for access by the persisters, until the UoW commit is finished.
     */
    public function computeChangeSets(): void
    {
        // Compute changes for INSERTed entities first. This must always happen.
        $this->computeScheduleInsertsChangeSets();

        // Compute changes for other MANAGED entities. Change tracking policies take effect here.
        foreach ($this->identityMap->all() as $className => $entities) {
            $class = $this->em->getClassMetadata($className);

            // Read-only scalar state needs no dirty check; associations still participate.
            if ($class->isReadOnly && $class->associationMappings === []) {
                continue;
            }

            $entitiesToProcess = match (true) {
                $class->isChangeTrackingDeferredImplicit() => $entities,
                isset($this->scheduledForSynchronization[$className]) => $this->scheduledForSynchronization[$className],
                default => [],
            };

            $plan = $this->unchangedGroupPlan($class, $entitiesToProcess);
            $scalarPlan = $plan !== null && $class->associationMappings === [] && $this->readOnlyObjects === []
                ? ScalarChangeTrackingPlan::forMetadata($class)
                : null;
            $groups = $plan === null ? [$entitiesToProcess] : array_chunk($entitiesToProcess, 256);
            foreach ($groups as $group) {
                if ($scalarPlan !== null ? $this->computeScalarGroup($class, $group, $scalarPlan)
                    : ($plan !== null && $this->isUnchangedGroup($class, $group, $plan[0], $plan[1], $plan[2]))
                ) {
                    continue;
                }

                // After the first uncertain entity, normal traversal can run
                // callbacks or discover new entities affecting later groups.
                $plan = $scalarPlan = null;
                foreach ($group as $entity) {
                    // Ignore uninitialized proxy objects
                    if ($this->unitOfWork->isUninitializedObject($entity)) {
                        continue;
                    }

                    // Only MANAGED entities that are NOT SCHEDULED FOR INSERTION OR DELETION are processed here.
                    $oid = spl_object_id($entity);

                    if (! $this->flushQueues->hasInsertion($oid) && ! $this->flushQueues->hasDeletion($oid) && isset($this->entityStateManager->states[$oid])) {
                        $this->unitOfWork->computeChangeSet($class, $entity);
                    }
                }
            }
        }
    }

    /** @param list<object> $group */
    private function computeScalarGroup(ClassMetadata $class, array $group, ScalarChangeTrackingPlan $plan): bool
    {
        // Leave the whole group untouched if an entity needs ordinary traversal.
        $updates = [];
        foreach ($group as $entity) {
            $oid = spl_object_id($entity);
            $original = $this->entityStateManager->snapshots[$oid] ?? null;
            if ($original === null || ($this->entityStateManager->states[$oid] ?? null) !== UnitOfWork::STATE_MANAGED) {
                return false;
            }

            $result = $plan->compare($class, $entity, $original);
            if ($result === null) {
                return false;
            }
            if ($result !== []) {
                $updates[$oid] = [$entity, $result];
            }
        }

        foreach ($updates as $oid => [$entity, [$changeSet, $actualData]]) {
            $this->entityChangeSets[$oid] = $changeSet;
            $this->entityStateManager->snapshots[$oid] = $actualData;
            $this->flushQueues->addUpdate($entity);
        }

        return true;
    }

    /**
     * Determine whether the class can be scanned without invoking accessors.
     *
     * @param array<array-key, object> $entities
     * @return array{list<string>, array<string, ClassMetadata>, array<string, ClassMetadata|null>}|null
     */
    private function unchangedGroupPlan(ClassMetadata $class, array $entities): ?array
    {
        if (count($entities) < 64 || $this->unitOfWork::class !== UnitOfWork::class
            || $this->em::class !== EntityManager::class
            || ! $class->isChangeTrackingDeferredImplicit() || ! $class->isIdentifierNatural()
            || $class->isReadOnly || $class->versionField !== null || $class->embeddedClasses !== []
            || ! $class->isInheritanceTypeNone() || $class->reflClass?->getParentClass() !== false
            || $this->flushQueues->insertions() !== [] || $this->flushQueues->deletions() !== []
            || ! $this->lazyReferenceTracker->isEmpty()
            || ($this->listenersInvoker->getSubscribedSystems($class, Events::preFlush) & ~ListenersInvoker::INVOKE_MANAGER) !== 0
        ) {
            return null;
        }

        $toOne = $collections = [];
        $metadataFactory = $this->em->getMetadataFactory();
        foreach ($class->associationMappings as $field => $mapping) {
            if ($mapping->isToOne()) {
                $toOne[$field] = $mapping->isManyToOne() && $metadataFactory->hasMetadataFor($mapping->targetEntity)
                    ? $this->em->getClassMetadata($mapping->targetEntity)
                    : null;
                continue;
            }
            if (! $mapping->isToMany() || ! $metadataFactory->hasMetadataFor($mapping->targetEntity)) {
                return null;
            }
            $collections[$field] = $this->em->getClassMetadata($mapping->targetEntity);
        }

        foreach ($class->propertyAccessors as $field => $accessor) {
            if ($accessor::class !== RawValuePropertyAccessor::class
                && ($accessor::class !== TypedNoDefaultPropertyAccessor::class || ! $accessor->usesRawPropertyAccess())
            ) {
                return null;
            }
            $property = $accessor->getUnderlyingReflector();
            if (! $property->isPublic() || $property->getName() !== $field || $property->hasHooks()
                || (! isset($class->associationMappings[$field])
                    && (! isset($class->fieldMappings[$field]) || $class->fieldMappings[$field]->enumType !== null))
            ) {
                return null;
            }
        }

        return [array_keys($class->fieldMappings), $collections, $toOne];
    }

    /**
     * @param array<array-key, object> $entities
     * @param list<string> $fields
     * @param array<string, ClassMetadata> $collections
     * @param array<string, ClassMetadata|null> $toOne
     */
    private function isUnchangedGroup(ClassMetadata $class, array $entities, array $fields, array $collections, array $toOne): bool
    {
        foreach ($entities as $entity) {
            $snapshot = $this->entityStateManager->snapshots[spl_object_id($entity)] ?? null;
            if ($entity::class !== $class->name || $snapshot === null || $class->reflClass->isUninitializedLazyObject($entity)) {
                return false;
            }

            $data = (array) $entity;
            foreach ($fields as $field) {
                if (! isset($data[$field], $snapshot[$field]) || $data[$field] !== $snapshot[$field]) {
                    return false;
                }
            }

            foreach ($toOne as $association => $targetClass) {
                if (! array_key_exists($association, $data) || ! array_key_exists($association, $snapshot)
                    || $data[$association] !== $snapshot[$association]) {
                    return false;
                }

                $target = $data[$association];
                if ($target !== null
                    && ($targetClass === null || ! $this->isManagedAssociationTarget($target, $targetClass))
                ) {
                    return false;
                }
            }

            foreach ($collections as $association => $targetClass) {
                $collection = $data[$association] ?? null;
                if (! $collection instanceof PersistentCollection || ($snapshot[$association] ?? null) !== $collection
                    || $collection->getOwner() !== $entity || $collection->isDirty()
                ) {
                    return false;
                }
                $storage = $collection->unwrap();
                if ($storage::class !== ArrayCollection::class) {
                    return false;
                }
                foreach ($storage->toArray() as $target) {
                    if (! $this->isManagedAssociationTarget($target, $targetClass)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    private function isManagedAssociationTarget(mixed $target, ClassMetadata $class): bool
    {
        return is_object($target) && $target::class === $class->name
            && ($this->entityStateManager->states[spl_object_id($target)] ?? null) === UnitOfWork::STATE_MANAGED
            && $class->reflClass !== null && ! $class->reflClass->isUninitializedLazyObject($target);
    }

    /**
     * Computes the changes of an association.
     *
     * @param mixed $value The value of the association.
     *
     * @throws ORMInvalidArgumentException
     * @throws ORMException
     */
    private function computeAssociationChanges(AssociationMapping $assoc, mixed $value): void
    {
        if ($this->unitOfWork->isUninitializedObject($value)) {
            return;
        }

        // If this collection is dirty, schedule it for updates
        if ($value instanceof PersistentCollection && $value->isDirty()) {
            $this->flushQueues->addCollectionUpdate($value);
            $this->flushQueues->visitCollection($value);
        }

        // Look through the entities, and in any of their associations,
        // for transient (new) entities, recursively. ("Persistence by reachability")
        // Unwrap. Uninitialized collections will simply be empty.
        $unwrappedValue = $assoc->isToOne() ? [$value] : $value->unwrap();
        $targetClass    = $this->em->getClassMetadata($assoc->targetEntity);

        foreach ($unwrappedValue as $key => $entry) {
            if (! ($entry instanceof $targetClass->name)) {
                throw ORMInvalidArgumentException::invalidAssociation($targetClass, $assoc, $entry);
            }

            $state = $this->unitOfWork->getEntityState($entry, UnitOfWork::STATE_NEW);

            if (! ($entry instanceof $assoc->targetEntity)) {
                throw UnexpectedAssociationValue::create(
                    $assoc->sourceEntity,
                    $assoc->fieldName,
                    get_debug_type($entry),
                    $assoc->targetEntity,
                );
            }

            switch ($state) {
                case UnitOfWork::STATE_NEW:
                    if (! $assoc->isCascadePersist()) {
                        /*
                         * For now just record the details, because this may
                         * not be an issue if we later discover another pathway
                         * through the object-graph where cascade-persistence
                         * is enabled for this object.
                         */
                        $this->nonCascadedNewDetectedEntities[spl_object_id($entry)] = [$assoc, $entry];

                        break;
                    }

                    ($this->persistNew)($targetClass, $entry);
                    $this->unitOfWork->computeChangeSet($targetClass, $entry);

                    break;

                case UnitOfWork::STATE_REMOVED:
                    // Consume the $value as array (it's either an array or an ArrayAccess)
                    // and remove the element from Collection.
                    if (! $assoc->isToMany()) {
                        break;
                    }

                    $this->flushQueues->removeCollectionElementAfterCommit($value, $key);
                    break;

                case UnitOfWork::STATE_DETACHED:
                    // Can actually not happen right now as we assume STATE_NEW,
                    // so the exception will be raised from the DBAL layer (constraint violation).
                    throw ORMInvalidArgumentException::detachedEntityFoundThroughRelationship($assoc, $entry);

                default:
                    // MANAGED associated entities are already taken into account
                    // during changeset calculation anyway, since they are in the identity map.
            }
        }
    }

    /**
     * INTERNAL:
     * Computes the changeset of an individual entity, independently of the
     * computeChangeSets() routine that is used at the beginning of a UnitOfWork#commit().
     *
     * The passed entity must be a managed entity. If the entity already has a change set
     * because this method is invoked during a commit cycle then the change sets are added.
     * whereby changes detected in this method prevail.
     *
     * @param ClassMetadata $class  The class descriptor of the entity.
     * @param object        $entity The entity for which to (re)calculate the change set.
     * @phpstan-param ClassMetadata<T> $class
     * @phpstan-param T $entity
     *
     * @throws ORMInvalidArgumentException If the passed entity is not MANAGED.
     *
     * @template T of object
     * @ignore
     */
    public function recomputeSingleEntityChangeSet(ClassMetadata $class, object $entity): void
    {
        $oid = spl_object_id($entity);

        if (! isset($this->entityStateManager->states[$oid]) || $this->entityStateManager->states[$oid] !== UnitOfWork::STATE_MANAGED) {
            throw ORMInvalidArgumentException::entityNotManaged($entity);
        }

        if ($class->isReadOnly && ! $this->flushQueues->hasInsertion($oid)) {
            return;
        }

        $actualData = null;
        if (! $this->flushQueues->hasInsertion($oid) && ! $this->lazyReferenceTracker->contains($entity)) {
            $plan = ScalarChangeTrackingPlan::forMetadata($class);
            $actualData = $plan?->readForRecompute($class, $entity);
        }
        if ($actualData === null) {
            $actualData = [];

            foreach ($class->propertyAccessors as $name => $refProp) {
                if (
                    (! $class->isIdentifier($name) || ! $class->isIdGeneratorIdentity())
                    && ($name !== $class->versionField)
                    && ! $class->isCollectionValuedAssociation($name)
                ) {
                    $actualData[$name] = $refProp->getValue($entity);
                }
            }
        }

        if (! isset($this->entityStateManager->snapshots[$oid])) {
            throw new RuntimeException('Cannot call recomputeSingleEntityChangeSet before computeChangeSet on an entity.');
        }

        $originalData = $this->entityStateManager->snapshots[$oid];
        $changeSet    = [];
        // Recompute historically treats a missing baseline as null; ordinary dirty checking skips it.
        $comparisonData = $originalData + array_fill_keys(array_keys($actualData), null);
        $scalarChanges = $this->flushQueues->hasInsertion($oid)
            ? ChangeSetComputer::compareForInsert($class, $comparisonData, $actualData)
            : ChangeSetComputer::compare($class, $comparisonData, $actualData);

        // Retain the original field ordering and strict to-one comparison in the coordinator.
        foreach ($actualData as $propName => $actualValue) {
            if (isset($class->associationMappings[$propName])) {
                $orgValue = $originalData[$propName] ?? null;
                if ($orgValue !== $actualValue) {
                    $changeSet[$propName] = [$orgValue, $actualValue];
                }
            } elseif (isset($scalarChanges[$propName])) {
                $changeSet[$propName] = $scalarChanges[$propName];
            }
        }

        if ($changeSet) {
            if (isset($this->entityChangeSets[$oid])) {
                $this->entityChangeSets[$oid] = [...$this->entityChangeSets[$oid], ...$changeSet];
            } elseif (! $this->flushQueues->hasInsertion($oid)) {
                $this->entityChangeSets[$oid] = $changeSet;
                $this->flushQueues->addUpdate($entity);
            }

            $this->entityStateManager->snapshots[$oid] = $actualData;
        }
    }

    /**
     * Checks whether an entity is registered to be checked in the unit of work.
     */
    public function isScheduledForDirtyCheck(object $entity): bool
    {
        $rootEntityName = $this->em->getClassMetadata($entity::class)->name;

        return isset($this->scheduledForSynchronization[$rootEntityName][spl_object_id($entity)]);
    }

    /**
     * Schedules an entity for dirty-checking at commit-time.
     *
     * @todo Rename: scheduleForSynchronization
     */
    public function scheduleForDirtyCheck(object $entity): void
    {
        $rootClassName = $this->em->getClassMetadata($entity::class)->name;

        $this->scheduledForSynchronization[$rootClassName][spl_object_id($entity)] = $entity;
    }

    /**
     * Notifies this UnitOfWork of a property change in an entity.
     *
     * {@inheritDoc}
     */
    public function propertyChanged(object $sender, string $propertyName, mixed $oldValue, mixed $newValue): void
    {
        $oid   = spl_object_id($sender);
        $class = $this->em->getClassMetadata($sender::class);

        if ($class->isReadOnly) {
            return;
        }

        $isAssocField = isset($class->associationMappings[$propertyName]);

        if (! $isAssocField && ! isset($class->fieldMappings[$propertyName])) {
            return; // ignore non-persistent fields
        }

        // Update changeset and mark entity for synchronization
        $this->entityChangeSets[$oid][$propertyName] = [$oldValue, $newValue];

        if (! isset($this->scheduledForSynchronization[$class->name][$oid])) {
            $this->unitOfWork->scheduleForDirtyCheck($sender);
        }
    }

    /** @internal Hydration must not overwrite a locally changed to-one, even after changeset computation. */
    public function isToOneAssociationChanged(object $entity, string $field): bool
    {
        $oid = spl_object_id($entity);
        $original = $this->entityStateManager->snapshots[$oid] ?? [];
        if (array_key_exists($field, $this->entityChangeSets[$oid] ?? [])) {
            $original[$field] = $this->entityChangeSets[$oid][$field][0];
        }
        if (! array_key_exists($field, $original)) {
            return false;
        }
        $accessor = $this->em->getClassMetadata($entity::class)->propertyAccessors[$field];
        // Inspecting an unloaded property must not initialize its lazy owner recursively.
        return ! $accessor->getUnderlyingReflector()->isLazy($entity)
            && $accessor->getValue($entity) !== $original[$field];
    }

    /** @throws ORMInvalidArgumentException */
    public function assertThatThereAreNoUnintentionallyNonPersistedAssociations(): void
    {
        $entitiesNeedingCascadePersist = array_diff_key($this->nonCascadedNewDetectedEntities, $this->flushQueues->insertions());

        $this->nonCascadedNewDetectedEntities = [];

        if ($entitiesNeedingCascadePersist) {
            throw ORMInvalidArgumentException::newEntitiesFoundThroughRelationships(
                array_values($entitiesNeedingCascadePersist),
            );
        }
    }

}
