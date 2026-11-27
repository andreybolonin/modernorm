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
use ModernORM\EntityManagerInterface;
use ModernORM\UnitOfWork;
use ModernORM\Events;
use ModernORM\Event\ListenersInvoker;
use ModernORM\Event\PostPersistEventArgs;
use ModernORM\Event\PostRemoveEventArgs;
use ModernORM\Event\PostUpdateEventArgs;
use ModernORM\Event\PreUpdateEventArgs;
use ModernORM\Persisters\Entity\BasicEntityPersister;
use ModernORM\Persisters\Entity\EntityPersister;
use ModernORM\Persisters\Collection\CollectionPersister;
use ModernORM\PersistentCollection;

/** @internal Orders and executes flush writes; transaction boundaries remain in UnitOfWork. */
final class FlushExecutor
{
    private readonly WriteOrderPlanner $writeOrderPlanner;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UnitOfWork $unitOfWork,
        private readonly FlushOperationQueues $flushQueues,
        private readonly EntityStateManager $entityStateManager,
        private readonly ListenersInvoker $listenersInvoker,
        private readonly ChangeTrackingCoordinator $changeTrackingCoordinator,
    ) {
        $this->writeOrderPlanner = new WriteOrderPlanner();
    }

    /** Execute one write phase; phase measurement, COMMIT and recovery belong to UnitOfWork. */
    public function execute(): void
    {
        // Collection deletions (deletions of complete collections)
        self::executeCollectionDeletions($this->collectionDeletionOperations());

        if ($this->flushQueues->insertions()) {
            // Perform entity insertions first, so that all new entities have their rows in the database
            // and can be referred to by foreign keys. The commit order only needs to take new entities
            // into account (new entities referring to other new entities), since all other types (entities
            // with updates or scheduled deletions) are currently not a problem, since they are already
            // in the database.
            $this->executeInserts();
        }

        if ($this->flushQueues->updates()) {
            // Updates are executed in a consistent order (class name, then entity ID (hash)) to eliminate deadlock
            // risk in concurrent update transactions ("Process X waits for ShareLock on transaction Y; blocked
            // by process Z").
            $this->executePendingUpdates();
        }

        // Extra updates that were requested by persisters.
        // This may include foreign keys that could not be set when an entity was inserted,
        // which may happen in the case of circular foreign key relationships.
        if ($this->flushQueues->extraUpdates()) {
            $this->executeExtraUpdates();
        }

        // Collection updates (deleteRows, updateRows, insertRows)
        // No particular order is necessary, since all entities themselves are already
        // in the database
        self::executeCollectionUpdates($this->collectionUpdateOperations());

        // Entity deletions come last. Their order only needs to take care of other deletions
        // (first delete entities depending upon others, before deleting depended-upon entities).
        if ($this->flushQueues->deletions()) {
            $this->executePendingDeletions();
        }
    }

    /** @return \Generator<int, array{PersistentCollection<array-key, object>, CollectionPersister}> */
    private function collectionDeletionOperations(): \Generator
    {
        foreach ($this->flushQueues->collectionDeletions() as $collection) {
            $owner = $collection->getOwner();
            // Deferred-explicit owners must be scheduled for dirty checking to delete their collection.
            if ($this->em->getClassMetadata($owner::class)->isChangeTrackingDeferredImplicit() || $this->unitOfWork->isScheduledForDirtyCheck($owner)) {
                yield [$collection, $this->unitOfWork->getCollectionPersister($collection->getMapping())];
            }
        }
    }

    /** @return \Generator<int, array{PersistentCollection<array-key, object>, CollectionPersister}> */
    private function collectionUpdateOperations(): \Generator
    {
        foreach ($this->flushQueues->collectionUpdates() as $collection) {
            yield [$collection, $this->unitOfWork->getCollectionPersister($collection->getMapping())];
        }
    }

    /** Executes any extra updates that have been scheduled. */
    private function executeExtraUpdates(): void
    {
        self::executeUpdates($this->extraUpdateOperations());
        $this->flushQueues->clearExtraUpdates();
    }

    /** @return \Generator<int, array{object, EntityPersister}> */
    private function extraUpdateOperations(): \Generator
    {
        foreach ($this->flushQueues->extraUpdates() as $oid => $update) {
            [$entity, $changeset] = $update;

            $this->changeTrackingCoordinator->replaceChangeSet($oid, $changeset);
            yield [$entity, $this->unitOfWork->getEntityPersister($entity::class)];
        }
    }

    /**
     * Executes entity insertions
     */
    private function executeInserts(): void
    {
        $batchedByType    = InsertBatch::batchByEntityType($this->em, $this->computeInsertExecutionOrder());
        $eventsToDispatch = [];
        $onQueued = function (object $entity): void {
            $this->flushQueues->removeInsertion(spl_object_id($entity));
        };

        foreach ($batchedByType as $batch) {
            $class     = $batch->class;
            $invoke    = $this->listenersInvoker->getSubscribedSystems($class, Events::postPersist);
            $persister = $this->unitOfWork->getEntityPersister($class->name);

            self::executeInsertBatch($batch, $persister, $onQueued);

            foreach ($batch->entities as $entity) {
                if ($invoke !== ListenersInvoker::INVOKE_NONE) {
                    $eventsToDispatch[] = ['class' => $class, 'entity' => $entity, 'invoke' => $invoke];
                }
            }
        }

        // Defer dispatching `postPersist` events to until all entities have been inserted and post-insert
        // IDs have been assigned.
        foreach ($eventsToDispatch as $event) {
            $this->listenersInvoker->invoke(
                $event['class'],
                Events::postPersist,
                $event['entity'],
                new PostPersistEventArgs($event['entity'], $this->em),
                $event['invoke'],
            );
        }
    }

    /**
     * Executes all entity updates
     */
    private function executePendingUpdates(): void
    {
        $previous = $statement = null;
        foreach ($this->updateOperations() as [$entity, $persister, $canReuse]) {
            if ($persister !== $previous || ! $canReuse) {
                $statement = null;
            }
            $previous = $persister;
            if ($canReuse && $persister::class === BasicEntityPersister::class) {
                $persister->updateWithStatementReuse($entity, $statement);
            } else {
                $persister->update($entity);
            }
        }
    }

    /**
     * Resume queue cleanup and postUpdate only after the yielded SQL write succeeds.
     * @return \Generator<int, array{object, EntityPersister, bool}>
     */
    private function updateOperations(): \Generator
    {
        $entities = $this->computeUpdateExecutionOrder();

        foreach ($entities as $entity) {
            $oid = spl_object_id($entity);
            $class = $this->em->getClassMetadata($entity::class);
            if ($class->isReadOnly) {
                $this->flushQueues->removeUpdate($oid);
                $this->changeTrackingCoordinator->replaceChangeSet($oid, null);
                continue;
            }

            $persister        = $this->unitOfWork->getEntityPersister($class->name);
            $preUpdateInvoke  = $this->listenersInvoker->getSubscribedSystems($class, Events::preUpdate);
            $postUpdateInvoke = $this->listenersInvoker->getSubscribedSystems($class, Events::postUpdate);

            if ($preUpdateInvoke !== ListenersInvoker::INVOKE_NONE) {
                $this->listenersInvoker->invoke($class, Events::preUpdate, $entity, new PreUpdateEventArgs($entity, $this->em, $this->unitOfWork->getEntityChangeSet($entity)), $preUpdateInvoke);

                $this->unitOfWork->recomputeSingleEntityChangeSet($class, $entity);
            }

            if ($this->unitOfWork->getEntityChangeSet($entity) !== []) {
                yield [$entity, $persister, $preUpdateInvoke === ListenersInvoker::INVOKE_NONE && $postUpdateInvoke === ListenersInvoker::INVOKE_NONE];
            }

            $this->flushQueues->removeUpdate($oid);

            if ($postUpdateInvoke !== ListenersInvoker::INVOKE_NONE) {
                $this->listenersInvoker->invoke($class, Events::postUpdate, $entity, new PostUpdateEventArgs($entity, $this->em), $postUpdateInvoke);
            }
        }
    }

    /**
     * Executes all entity deletions
     */
    private function executePendingDeletions(): void
    {
        self::executeDeletions($this->deletionOperations());
    }

    /**
     * Remove identity before SQL; resume state cleanup only after a successful write.
     * Dispatch postRemove only when the executor has consumed all deletions.
     * @return \Generator<int, array{object, EntityPersister}>
     */
    private function deletionOperations(): \Generator
    {
        $entities         = $this->computeDeleteExecutionOrder();
        $eventsToDispatch = [];

        foreach ($entities as $entity) {
            $this->unitOfWork->removeFromIdentityMap($entity);

            $oid       = spl_object_id($entity);
            $class     = $this->em->getClassMetadata($entity::class);
            $persister = $this->unitOfWork->getEntityPersister($class->name);
            $invoke    = $this->listenersInvoker->getSubscribedSystems($class, Events::postRemove);

            yield [$entity, $persister];

            $this->flushQueues->removeDeletion($oid);
            $this->entityStateManager->forgetAfterDeletion($oid);
            $identifierProperty = $class->propertyAccessors[$class->identifier[0]]->getUnderlyingReflector();
            $identifierType = $identifierProperty->getType();
            // PHP forbids unsetting backed hooked properties. Keep non-nullable
            // hooked IDs after deletion, just as we retain readonly IDs.
            $cannotResetHookedId = $identifierProperty->hasHooks()
                && $identifierType !== null && ! $identifierType->allowsNull();
            if (
                ! $class->isIdentifierNatural() &&
                ! $identifierProperty->isReadOnly() &&
                ! $cannotResetHookedId
            ) {
                $class->propertyAccessors[$class->identifier[0]]->setValue($entity, null);
            }

            if ($invoke !== ListenersInvoker::INVOKE_NONE) {
                $eventsToDispatch[] = ['class' => $class, 'entity' => $entity, 'invoke' => $invoke];
            }
        }

        // Defer dispatching `postRemove` events to until all entities have been removed.
        foreach ($eventsToDispatch as $event) {
            $this->listenersInvoker->invoke(
                $event['class'],
                Events::postRemove,
                $event['entity'],
                new PostRemoveEventArgs($event['entity'], $this->em),
                $event['invoke'],
            );
        }
    }

    /** @return list<object> */
    private function computeInsertExecutionOrder(): array
    {
        $entities = $this->flushQueues->insertions();
        return $this->writeOrderPlanner->orderInserts($entities, $this->observeInsertDependencies($entities));
    }

    /**
     * @param array<int, object> $entities
     * @return \Generator<int, InsertDependency>
     */
    private function observeInsertDependencies(array $entities): \Generator
    {
        foreach ($entities as $entity) {
            $class = $this->em->getClassMetadata($entity::class);
            foreach ($class->associationMappings as $association) {
                if (! $association->isToOneOwningSide()) {
                    continue;
                }
                $target = $class->getFieldValue($entity, $association->fieldName);
                if ($target === null || ! isset($entities[spl_object_id($target)])) {
                    continue;
                }
                $assignedSelfReference = $target === $entity && $class->isIdentifierNatural();
                // Match the previous default and first-join-column policy.
                $column = reset($association->joinColumns);
                yield new InsertDependency(
                    $entity,
                    $target,
                    ! isset($column->nullable) || $column->nullable,
                    $assignedSelfReference,
                );
            }
        }
    }

    /** @return list<object> */
    private function computeUpdateExecutionOrder(): array
    {
        return $this->writeOrderPlanner->orderUpdates($this->flushQueues->updates(), $this->unitOfWork->getIdHashByEntity(...));
    }

    /** @return list<object> */
    private function computeDeleteExecutionOrder(): array
    {
        $entities = $this->flushQueues->deletions();
        return $this->writeOrderPlanner->orderDeletes(
            $entities,
            $this->observeCascadeDeleteDependencies($entities),
            $this->observeEntityDeleteDependencies($entities),
        );
    }

    /**
     * @param array<int, object> $entities
     * @return \Generator<int, DeleteDependency>
     */
    private function observeCascadeDeleteDependencies(array $entities): \Generator
    {
        foreach ($entities as $entity) {
            yield from $this->observeDeleteDependencies($entities, $entity, true);
        }
    }

    /**
     * @param array<int, object> $entities
     * @return \Generator<int, EntityDeleteDependencies>
     */
    private function observeEntityDeleteDependencies(array $entities): \Generator
    {
        foreach ($entities as $entity) {
            yield new EntityDeleteDependencies($entity, $this->observeDeleteDependencies($entities, $entity, false));
        }
    }

    /**
     * Reads only the associations considered by the corresponding old ordering pass.
     *
     * @param array<int, object> $entities
     * @return \Generator<int, DeleteDependency>
     */
    private function observeDeleteDependencies(array $entities, object $entity, bool $cascadeOnly): \Generator
    {
        $class = $this->em->getClassMetadata($entity::class);
        foreach ($class->associationMappings as $association) {
            if (! $association->isToOneOwningSide()) {
                continue;
            }
            $column = reset($association->joinColumns);
            $onDelete = isset($column->onDelete) ? strtolower($column->onDelete) : null;
            if (($cascadeOnly && $onDelete !== 'cascade') || (! $cascadeOnly && $onDelete === 'set null')) {
                continue;
            }
            $target = $class->getFieldValue($entity, $association->fieldName);
            if ($target !== null && isset($entities[spl_object_id($target)])) {
                yield new DeleteDependency($entity, $target, $onDelete);
            }
        }
    }

    public function synchronizeCollections(): void
    {
        // Unset removed entities from collections, and take new snapshots from
        // all visited collections.
        foreach ($this->flushQueues->visitedCollections() as $coid => $coll) {
            foreach ($this->flushQueues->pendingCollectionElementRemovals($coid) as $key => $valueIgnored) {
                unset($coll[$key]);
            }

            $coll->takeSnapshot();
        }
    }

    /**
     * Acknowledge each successfully queued entity before persister execution, so post-insert
     * ID assignment and persister callbacks observe the coordinator's updated insertion queue.
     *
     * @template TEntity of object
     * @param InsertBatch<TEntity> $batch
     * @param Closure(TEntity): void $onQueued
     */
    public static function executeInsertBatch(InsertBatch $batch, EntityPersister $persister, Closure $onQueued): void
    {
        foreach ($batch->entities as $entity) {
            $persister->addInsert($entity);
            $onQueued($entity);
        }

        $persister->executeInserts();
    }

    /**
     * Consume lazily: the coordinator prepares the next operation only after this write
     * succeeds. Exceptions stop iteration without resuming its post-write coordination.
     *
     * @param iterable<array{object, EntityPersister}> $operations
     */
    public static function executeUpdates(iterable $operations): void
    {
        foreach ($operations as [$entity, $persister]) {
            $persister->update($entity);
        }
    }

    /** @param iterable<array{object, EntityPersister}> $operations */
    public static function executeDeletions(iterable $operations): void
    {
        foreach ($operations as [$entity, $persister]) {
            $persister->delete($entity);
        }
    }

    /**
     * @template TKey of array-key
     * @template TEntity of object
     * @param iterable<array{PersistentCollection<TKey, TEntity>, CollectionPersister}> $operations
     */
    public static function executeCollectionDeletions(iterable $operations): void
    {
        foreach ($operations as [$collection, $persister]) {
            $persister->delete($collection);
        }
    }

    /**
     * @template TKey of array-key
     * @template TEntity of object
     * @param iterable<array{PersistentCollection<TKey, TEntity>, CollectionPersister}> $operations
     */
    public static function executeCollectionUpdates(iterable $operations): void
    {
        foreach ($operations as [$collection, $persister]) {
            $persister->update($collection);
        }
    }
}
