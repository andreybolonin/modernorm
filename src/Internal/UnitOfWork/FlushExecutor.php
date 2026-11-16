<?php

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

use Closure;
use ModernORM\Persisters\Entity\EntityPersister;
use ModernORM\Persisters\Collection\CollectionPersister;
use ModernORM\PersistentCollection;

/** @internal Executes prepared writes without owning transaction or UnitOfWork state. */
final class FlushExecutor
{
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
