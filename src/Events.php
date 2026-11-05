<?php

declare(strict_types=1);

namespace ModernORM;

/**
 * Container for all ORM events.
 *
 * This class cannot be instantiated.
 */
final class Events
{
    /**
     * Private constructor. This class is not meant to be instantiated.
     */
    private function __construct()
    {
    }

    /**
     * The preRemove event occurs for a given entity before the respective
     * EntityManager remove operation for that entity is executed.
     *
     * This is an entity lifecycle event.
     */
    public const preRemove = 'preRemove';

    /**
     * The postRemove event occurs for an entity after the entity has
     * been deleted. It will be invoked after the database delete operations.
     *
     * This is an entity lifecycle event.
     */
    public const postRemove = 'postRemove';

    /**
     * The prePersist event occurs for a given entity before the respective
     * EntityManager persist operation for that entity is executed.
     *
     * This is an entity lifecycle event.
     */
    public const prePersist = 'prePersist';

    /**
     * The postPersist event occurs for an entity after the entity has
     * been made persistent. It will be invoked after the database insert operations.
     * Generated primary key values are available in the postPersist event.
     *
     * This is an entity lifecycle event.
     */
    public const postPersist = 'postPersist';

    /**
     * The preUpdate event occurs before the database update operations to
     * entity data.
     *
     * This is an entity lifecycle event.
     */
    public const preUpdate = 'preUpdate';

    /**
     * The postUpdate event occurs after the database update operations to
     * entity data.
     *
     * This is an entity lifecycle event.
     */
    public const postUpdate = 'postUpdate';

    /**
     * The postLoad event occurs for an entity after the entity has been loaded
     * into the current EntityManager from the database or after the refresh operation
     * has been applied to it.
     *
     * Note that the postLoad event occurs for an entity before any associations have been
     * initialized. Therefore, it is not safe to access associations in a postLoad callback
     * or event handler.
     *
     * This is an entity lifecycle event.
     */
    public const postLoad = 'postLoad';

    /**
     * The loadClassMetadata event occurs after the mapping metadata for a class
     * has been loaded from a mapping source (attributes/xml).
     */
    public const loadClassMetadata = 'loadClassMetadata';

    /**
     * The onClassMetadataNotFound event occurs whenever loading metadata for a class
     * failed.
     */
    public const onClassMetadataNotFound = 'onClassMetadataNotFound';

    /**
     * The preFlush event occurs when the EntityManager#flush() operation is invoked,
     * but before any changes to managed entities have been calculated. This event is
     * always raised right after EntityManager#flush() call.
     */
    public const preFlush = 'preFlush';

    /**
     * The onFlush event occurs when the EntityManager#flush() operation is invoked,
     * after any changes to managed entities have been determined but before any
     * actual database operations are executed. It is also raised when the UnitOfWork
     * has no pending changes. Changes made here require explicit changeset computation.
     */
    public const onFlush = 'onFlush';

    /**
     * The postFlush event occurs when the EntityManager#flush() operation is invoked and
     * after successful completion of its transaction level, or when there are no pending
     * changes. An outer transaction may still be active. This event is skipped when an
     * earlier flush phase fails. An exception here does not undo an already completed commit.
     */
    public const postFlush = 'postFlush';

    /**
     * The onClear event occurs when the EntityManager#clear() operation is invoked,
     * after all references to entities have been removed from the unit of work.
     */
    public const onClear = 'onClear';
}
