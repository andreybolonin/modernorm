<?php

declare(strict_types=1);

namespace ModernORM;

use ModernORM\Exception\EntityNotFoundException;
use ModernORM\Exception\ORMInvalidArgumentException;
use ModernORM\Exception\OptimisticLockException;
use ModernORM\Exception\TransactionRequiredException;
use BackedEnum;
use ModernORM\Batch\LazyCollectionQueue;
use DateTimeInterface;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\EventDispatcher;
use Doctrine\DBAL;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\LockMode;
use ModernORM\Event\ListenersInvoker;
use ModernORM\Event\OnClearEventArgs;
use ModernORM\Event\OnFlushEventArgs;
use ModernORM\Event\PostFlushEventArgs;
use ModernORM\Event\PreFlushEventArgs;
use ModernORM\Event\PrePersistEventArgs;
use ModernORM\Event\PreRemoveEventArgs;
use ModernORM\Exception\EntityIdentityCollisionException;
use ModernORM\Id\AssignedGenerator;
use ModernORM\Internal\HydrationCompleteHandler;
use ModernORM\Internal\UnitOfWork\AssociationChangePlanner;
use ModernORM\Internal\UnitOfWork\AssociationMembership;
use ModernORM\Internal\UnitOfWork\ChangeTrackingCoordinator;
use ModernORM\Internal\UnitOfWork\EntityHydrator;
use ModernORM\Internal\UnitOfWork\CascadeProcessor;
use ModernORM\Internal\UnitOfWork\EagerLoadCoordinator;
use ModernORM\Internal\UnitOfWork\PersisterRegistry;
use ModernORM\Internal\UnitOfWork\FlushOperationQueues;
use ModernORM\Internal\UnitOfWork\FlushExecutor;
use ModernORM\Internal\UnitOfWork\IdentityMap;
use ModernORM\Internal\UnitOfWork\EntityStateManager;
use ModernORM\Internal\UnitOfWork\LazyReferenceTracker;
use ModernORM\Internal\UnitOfWork\OrphanRemovalReason;
use ModernORM\Mapping\AssociationMapping;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Exception\MappingException;
use ModernORM\Persisters\Collection\CollectionPersister;
use ModernORM\Persisters\Entity\EntityPersister;
use ModernORM\Utility\IdentifierFlattener;
use Doctrine\Persistence\PropertyChangedListener;
use Exception;
use InvalidArgumentException;
use Stringable;
use UnexpectedValueException;

use function array_combine;
use function array_key_exists;
use function array_values;
use function assert;
use function count;
use function get_debug_type;
use function implode;
use function in_array;
use function is_object;
use function spl_object_id;
use function sprintf;

/**
 * The UnitOfWork is responsible for tracking changes to objects during an
 * "object-level" transaction and for writing out changes to the database
 * in the correct order.
 *
 * Internal note: This class contains highly performance-sensitive code.
 */
class UnitOfWork implements PropertyChangedListener
{
    /**
     * An entity is in MANAGED state when its persistence is managed by an EntityManager.
     */
    public const STATE_MANAGED = 1;

    /**
     * An entity is new if it has just been instantiated (i.e. using the "new" operator)
     * and is not (yet) managed by an EntityManager.
     */
    public const STATE_NEW = 2;

    /**
     * A detached entity is an instance with persistent state and identity that is not
     * (or no longer) associated with an EntityManager (and a UnitOfWork).
     */
    public const STATE_DETACHED = 3;

    /**
     * A removed entity instance is an instance with a persistent identity,
     * associated with an EntityManager, whose persistent state will be deleted
     * on commit.
     */
    public const STATE_REMOVED = 4;

    /**
     * Hint used to collect all primary keys of associated entities during hydration
     * and execute it in a dedicated query afterwards
     *
     */
    public const HINT_DEFEREAGERLOAD = 'deferEagerLoad';

    private readonly IdentityMap $identityMap;

    private readonly EntityStateManager $entityStateManager;

    /**
     * The EventDispatcher used for dispatching events.
     */
    private readonly EventDispatcher $eventDispatcher;

    /**
     * The ListenersInvoker used for dispatching events.
     */
    private readonly ListenersInvoker $listenersInvoker;

    /**
     * The IdentifierFlattener used for manipulating identifiers
     */
    private readonly IdentifierFlattener $identifierFlattener;

    private readonly AssociationChangePlanner $associationChangePlanner;
    private readonly FlushOperationQueues $flushQueues;
    private readonly LazyReferenceTracker $lazyReferenceTracker;

    private readonly ChangeTrackingCoordinator $changeTrackingCoordinator;

    private readonly FlushExecutor $flushExecutor;

    private readonly EntityHydrator $entityHydrator;

    private readonly CascadeProcessor $cascadeProcessor;

    private readonly EagerLoadCoordinator $eagerLoadCoordinator;

    private readonly PersisterRegistry $persisterRegistry;

    protected bool $hasCache = false;

    /**
     * Helper for handling completion of hydration
     */
    private readonly HydrationCompleteHandler $hydrationCompleteHandler;

    private readonly LazyCollectionQueue $lazyCollectionQueue;

    /** @internal */
    public function getLazyCollectionQueue(): LazyCollectionQueue
    {
        return $this->lazyCollectionQueue;
    }

    private readonly ?Statistics $statistics;

    /**
     * Initializes a new UnitOfWork instance, bound to the given EntityManager.
     *
     * @param EntityManagerInterface $em The EntityManager that "owns" this UnitOfWork instance.
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        $this->cascadeProcessor = new CascadeProcessor($em, $this);
        $this->associationChangePlanner = new AssociationChangePlanner();
        $this->flushQueues = new FlushOperationQueues();
        $this->identityMap = new IdentityMap();
        $this->entityStateManager = new EntityStateManager($this->addToIdentityMap(...));
        $this->lazyReferenceTracker = new LazyReferenceTracker();
        $this->statistics = $em->getStatistics();
        $this->lazyCollectionQueue = new LazyCollectionQueue($em);
        $this->eventDispatcher          = $em->getEventManager();
        $this->listenersInvoker         = new ListenersInvoker($em);
        $this->hasCache                 = $em->getConfiguration()->isSecondLevelCacheEnabled();
        $this->identifierFlattener      = new IdentifierFlattener();
        $this->hydrationCompleteHandler = new HydrationCompleteHandler($this->listenersInvoker, $em);
        $this->changeTrackingCoordinator = new ChangeTrackingCoordinator(
            $em,
            $this,
            $this->entityStateManager,
            $this->identityMap,
            $this->flushQueues,
            $this->lazyReferenceTracker,
            $this->associationChangePlanner,
            $this->listenersInvoker,
            $this->persistNew(...),
        );
        $this->persisterRegistry = new PersisterRegistry($em, $this->hasCache);
        $this->eagerLoadCoordinator = new EagerLoadCoordinator($em, $this, $this->identifierFlattener);
        $this->entityHydrator = new EntityHydrator($em, $this, $this->entityStateManager, $this->identityMap, $this->eagerLoadCoordinator);
        $this->flushExecutor = new FlushExecutor(
            $em,
            $this,
            $this->flushQueues,
            $this->entityStateManager,
            $this->listenersInvoker,
            $this->changeTrackingCoordinator,
        );
    }

    /**
     * Commits the UnitOfWork, executing all operations that have been postponed
     * up to this point. The state of all managed entities will be synchronized with
     * the database.
     *
     * The operations are executed in the following order:
     *
     * 1) All collection deletions
     * 2) All entity insertions
     * 3) All entity updates
     * 4) All extra entity updates
     * 5) All collection updates
     * 6) All entity deletions
     *
     * @throws Exception
     */
    public function commit(): void
    {
        $connection = $this->em->getConnection();

        if ($connection instanceof PrimaryReadReplicaConnection) {
            $connection->ensureConnectedToPrimary();
        }

        $this->dispatchPreFlushEvent();

        // Compute changes done since last commit.
        $this->measurePhase(\ModernORM\Statistics\UnitOfWorkPhase::CHANGE_SETS, fn () => $this->computeChangeSets(), after: true);

        $onFlushDispatched = false;
        if (! $this->hasPendingOperations()) {
            $this->dispatchOnFlushEvent();
            $onFlushDispatched = true;
            // onFlush listeners may explicitly compute new work even when the initial queue was empty.
            if (! $this->hasPendingOperations()) {
                $this->dispatchPostFlushAfterCleanup();
                return;
            }
        }

        $this->changeTrackingCoordinator->assertThatThereAreNoUnintentionallyNonPersistedAssociations();

        if ($this->associationChangePlanner->hasCandidates()) {
            foreach ($this->associationChangePlanner->candidates() as $orphan) {
                if ($this->associationChangePlanner->isOrphan($this->observeOrphanMemberships($orphan))) {
                    $this->remove($orphan);
                }
            }
        }

        if (! $onFlushDispatched) {
            $this->dispatchOnFlushEvent();
        }

        $connection->beginTransaction();

        $successful = false;
        $commitFailure = null;

        try {
            $this->measurePhase(\ModernORM\Statistics\UnitOfWorkPhase::WRITES, $this->flushExecutor->execute(...));

            $this->commitFlushTransaction($connection, $commitFailure);

            $successful = true;
        } finally {
            if (! $successful) {
                $this->handleFailedFlush($connection, $commitFailure);
            }
        }

        $this->completeSuccessfulFlush();
    }

    /** Preserve the original COMMIT failure for uncertain-outcome cache recovery. */
    private function commitFlushTransaction(DBAL\Connection $connection, ?\Throwable &$commitFailure): void
    {
        $this->measurePhase(\ModernORM\Statistics\UnitOfWorkPhase::COMMIT, function () use ($connection, &$commitFailure): void {
            try {
                $connection->commit();
            } catch (DBAL\Exception $e) {
                $commitFailure = $e;
                throw new OptimisticLockException('Commit failed', null, $e);
            } catch (\Throwable $e) {
                $commitFailure = $e;
                throw $e;
            }
        });
    }

    /** The write/COMMIT failure path; successful completion and postFlush never enter here. */
    private function handleFailedFlush(DBAL\Connection $connection, ?\Throwable $commitFailure): void
    {
        try {
            $this->em->close();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        } finally {
            if ($commitFailure !== null && $this->hasCache) {
                // Nesting may be zero although the driver outcome is unknown.
                $connection->close();
                $this->persisterRegistry->afterTransactionCommitFailed($commitFailure);
            } elseif ($this->hasCache) {
                $this->persisterRegistry->afterTransactionRolledBack();
            }
        }
    }

    /** A flush transaction/savepoint completed; an outer transaction may still be active. */
    private function completeSuccessfulFlush(): void
    {
        if ($this->hasCache) {
            $this->persisterRegistry->afterTransactionComplete();
        }
        $this->flushExecutor->synchronizeCollections();
        $this->dispatchPostFlushAfterCleanup();
    }

    /** Clear completed work before listeners can schedule operations for the next flush. */
    private function dispatchPostFlushAfterCleanup(): void
    {
        $this->postCommitCleanup();
        $this->dispatchPostFlushEvent();
    }

    private function hasPendingOperations(): bool
    {
        return $this->flushQueues->hasPrimaryOperations() || $this->associationChangePlanner->hasCandidates();
    }

    private function measurePhase(\ModernORM\Statistics\UnitOfWorkPhase $phase, \Closure $work, bool $after = false): void
    {
        $measurement = $this->statistics?->start();
        if ($measurement === null) {
            $work();
            return;
        }
        $details = $after ? null : $this->phaseDetails($phase);
        $failed = true;
        try {
            $work();
            $failed = false;
        } finally {
            $this->statistics->recordUnitOfWork($measurement, $failed, $details ?? $this->phaseDetails($phase));
        }
    }

    private function phaseDetails(\ModernORM\Statistics\UnitOfWorkPhase $phase): \ModernORM\Statistics\UnitOfWorkDetails
    {
        return new \ModernORM\Statistics\UnitOfWorkDetails(
            $phase,
            $this->size(),
            count($this->flushQueues->insertions()),
            count($this->flushQueues->updates()),
            count($this->flushQueues->deletions()),
            count($this->flushQueues->collectionUpdates()),
            count($this->flushQueues->collectionDeletions()),
            count($this->flushQueues->extraUpdates())
        );
    }

    private function postCommitCleanup(): void
    {
        $this->flushQueues->clear();
        $this->changeTrackingCoordinator->clearAfterFlush();
        $this->associationChangePlanner->clear();
    }

    /**
     * Gets the changeset for an entity.
     *
     * @return mixed[][]
     * @phpstan-return array<string, array{mixed, mixed}|PersistentCollection>
     */
    public function & getEntityChangeSet(object $entity): array
    {
        return $this->changeTrackingCoordinator->getEntityChangeSet($entity);
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
        $this->changeTrackingCoordinator->computeChangeSet($class, $entity);
    }

    /**
     * Computes all the changes that have been done to entities and collections
     * since the last commit and stores these changes in the _entityChangeSet map
     * temporarily for access by the persisters, until the UoW commit is finished.
     */
    public function computeChangeSets(): void
    {
        $this->changeTrackingCoordinator->computeChangeSets();
    }

    /**
     * @phpstan-param ClassMetadata<T> $class
     * @phpstan-param T $entity
     *
     * @template T of object
     */
    private function persistNew(ClassMetadata $class, object $entity): void
    {
        $oid    = spl_object_id($entity);
        $invoke = $this->listenersInvoker->getSubscribedSystems($class, Events::prePersist);

        if ($invoke !== ListenersInvoker::INVOKE_NONE) {
            $this->listenersInvoker->invoke($class, Events::prePersist, $entity, new PrePersistEventArgs($entity, $this->em), $invoke);
        }

        $idGen = $class->idGenerator;

        if (! $idGen->isPostInsertGenerator()) {
            $idValue = $idGen->generateId($this->em, $entity);

            if (! $idGen instanceof AssignedGenerator) {
                $idValue = [$class->getSingleIdentifierFieldName() => $this->convertSingleFieldIdentifierToPHPValue($class, $idValue)];

                $class->setIdentifierValues($entity, $idValue);
            }

            $this->entityStateManager->identifiers[$oid] = $idValue;
        }

        $this->entityStateManager->states[$oid] = self::STATE_MANAGED;

        if (! $this->flushQueues->hasInsertion($oid)) {
            $this->scheduleForInsert($entity);
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
        $this->changeTrackingCoordinator->recomputeSingleEntityChangeSet($class, $entity);
    }

    /**
     * Schedules an entity for insertion into the database.
     * If the entity already has an identifier, it will be added to the identity map.
     *
     * @throws ORMInvalidArgumentException
     * @throws InvalidArgumentException
     */
    public function scheduleForInsert(object $entity): void
    {
        $oid = spl_object_id($entity);

        if ($this->flushQueues->hasUpdate($oid)) {
            throw new InvalidArgumentException('Dirty entity can not be scheduled for insertion.');
        }

        if ($this->flushQueues->hasDeletion($oid)) {
            throw ORMInvalidArgumentException::scheduleInsertForRemovedEntity($entity);
        }

        if (isset($this->entityStateManager->snapshots[$oid]) && ! $this->flushQueues->hasInsertion($oid)) {
            throw ORMInvalidArgumentException::scheduleInsertForManagedEntity($entity);
        }

        if ($this->flushQueues->hasInsertion($oid)) {
            throw ORMInvalidArgumentException::scheduleInsertTwice($entity);
        }

        $this->flushQueues->addInsertion($entity);

        if (isset($this->entityStateManager->identifiers[$oid])) {
            $this->addToIdentityMap($entity);
        }
    }

    /**
     * Checks whether an entity is scheduled for insertion.
     */
    public function isScheduledForInsert(object $entity): bool
    {
        return $this->flushQueues->hasInsertion(spl_object_id($entity));
    }

    /**
     * Schedules an entity for being updated.
     *
     * @throws ORMInvalidArgumentException
     */
    public function scheduleForUpdate(object $entity): void
    {
        $oid = spl_object_id($entity);

        if (! isset($this->entityStateManager->identifiers[$oid])) {
            throw ORMInvalidArgumentException::entityHasNoIdentity($entity, 'scheduling for update');
        }

        if ($this->flushQueues->hasDeletion($oid)) {
            throw ORMInvalidArgumentException::entityIsRemoved($entity, 'schedule for update');
        }

        if (! $this->flushQueues->hasUpdate($oid) && ! $this->flushQueues->hasInsertion($oid)) {
            $this->flushQueues->addUpdate($entity);
        }
    }

    /**
     * INTERNAL:
     * Schedules an extra update that will be executed immediately after the
     * regular entity updates within the currently running commit cycle.
     *
     * Extra updates for entities are stored as (entity, changeset) tuples.
     *
     * @phpstan-param array<string, array{mixed, mixed}>  $changeset The changeset of the entity (what to update).
     *
     * @ignore
     */
    public function scheduleExtraUpdate(object $entity, array $changeset): void
    {
        $this->flushQueues->addExtraUpdate($entity, $changeset);
    }

    /**
     * Checks whether an entity is registered as dirty in the unit of work.
     * Note: Is not very useful currently as dirty entities are only registered
     * at commit time.
     */
    public function isScheduledForUpdate(object $entity): bool
    {
        return $this->flushQueues->hasUpdate(spl_object_id($entity));
    }

    /**
     * Checks whether an entity is registered to be checked in the unit of work.
     */
    public function isScheduledForDirtyCheck(object $entity): bool
    {
        return $this->changeTrackingCoordinator->isScheduledForDirtyCheck($entity);
    }

    /**
     * INTERNAL:
     * Schedules an entity for deletion.
     */
    public function scheduleForDelete(object $entity): void
    {
        $oid = spl_object_id($entity);

        if ($this->flushQueues->hasInsertion($oid)) {
            if ($this->isInIdentityMap($entity)) {
                $this->removeFromIdentityMap($entity);
            }

            $this->flushQueues->removeInsertion($oid);
            unset($this->entityStateManager->states[$oid]);

            return; // entity has not been persisted yet, so nothing more to do.
        }

        if (! $this->isInIdentityMap($entity)) {
            return;
        }

        $this->flushQueues->removeUpdate($oid);

        if (! $this->flushQueues->hasDeletion($oid)) {
            $this->flushQueues->addDeletion($entity);
            $this->entityStateManager->states[$oid] = self::STATE_REMOVED;
        }
    }

    /**
     * Checks whether an entity is registered as removed/deleted with the unit
     * of work.
     */
    public function isScheduledForDelete(object $entity): bool
    {
        return $this->flushQueues->hasDeletion(spl_object_id($entity));
    }

    /**
     * Checks whether an entity is scheduled for insertion, update or deletion.
     */
    public function isEntityScheduled(object $entity): bool
    {
        $oid = spl_object_id($entity);

        return $this->flushQueues->hasInsertion($oid)
            || $this->flushQueues->hasUpdate($oid)
            || $this->flushQueues->hasDeletion($oid);
    }

    /**
     * INTERNAL:
     * Registers an entity in the identity map.
     * Entities are registered under their mapped class name.
     *
     * @return bool TRUE if the registration was successful, FALSE if the identity of
     * the entity in question is already managed.
     *
     * @throws ORMInvalidArgumentException
     * @throws EntityIdentityCollisionException
     *
     * @ignore
     */
    public function addToIdentityMap(object $entity): bool
    {
        $classMetadata = $this->em->getClassMetadata($entity::class);
        $idHash        = $this->getIdHashByEntity($entity);
        $className     = $classMetadata->name;

        return $this->identityMap->add($className, $idHash, $entity);
    }

    /**
     * Gets the id hash of an entity by its identifier.
     *
     * @param array<string|int, mixed> $identifier The identifier of an entity
     *
     * @return string The entity id hash.
     */
    final public static function getIdHashByIdentifier(array $identifier): string
    {
        foreach ($identifier as $k => $value) {
            if ($value instanceof BackedEnum) {
                $identifier[$k] = $value->value;
            }
        }

        return implode(
            ' ',
            $identifier,
        );
    }

    /**
     * Gets the id hash of an entity.
     *
     * @param object $entity The entity managed by Unit Of Work
     *
     * @return string The entity id hash.
     */
    public function getIdHashByEntity(object $entity): string
    {
        $identifier = $this->entityStateManager->identifiers[spl_object_id($entity)];

        if (empty($identifier) || in_array(null, $identifier, true)) {
            $classMetadata = $this->em->getClassMetadata($entity::class);

            throw ORMInvalidArgumentException::entityWithoutIdentity($classMetadata->name, $entity);
        }

        return self::getIdHashByIdentifier($identifier);
    }

    /**
     * Gets the state of an entity with regard to the current unit of work.
     *
     * @param int|null $assume The state to assume if the state is not yet known (not MANAGED or REMOVED).
     *                         This parameter can be set to improve performance of entity state detection
     *                         by potentially avoiding a database lookup if the distinction between NEW and DETACHED
     *                         is either known or does not matter for the caller of the method.
     * @phpstan-param self::STATE_*|null $assume
     *
     * @phpstan-return self::STATE_*
     */
    public function getEntityState(object $entity, int|null $assume = null): int
    {
        $oid = spl_object_id($entity);

        if (isset($this->entityStateManager->states[$oid])) {
            return $this->entityStateManager->states[$oid];
        }

        if ($assume !== null) {
            return $assume;
        }

        // State can only be NEW or DETACHED, because MANAGED/REMOVED states are known.
        // Note that you can not remember the NEW or DETACHED state in _entityStates since
        // the UoW does not hold references to such objects and the object hash can be reused.
        // More generally because the state may "change" between NEW/DETACHED without the UoW being aware of it.
        $class = $this->em->getClassMetadata($entity::class);
        $id    = $class->getIdentifierValues($entity);

        if (! $id) {
            return self::STATE_NEW;
        }

        if ($class->containsEnumIdentifier) {
            $id = $this->identifierFlattener->flattenIdentifier($class, $id);
        }

        $natural = $class->isIdentifierNatural();
        $versionValueIsTruthy = null;
        if ($natural && $class->isVersioned) {
            assert($class->versionField !== null);
            $versionValueIsTruthy = (bool) $class->getFieldValue($entity, $class->versionField);
        }
        // Natural identifiers never consult the generator, including versioned entities.
        $postInsert = ! $natural && $class->idGenerator->isPostInsertGenerator();

        return $this->entityStateManager->resolveIdentifiedState(
            $versionValueIsTruthy,
            $postInsert,
            fn (): bool => (bool) $this->tryGetById($id, $class->name)
                || $this->getEntityPersister($class->name)->exists($entity),
        );
    }

    /**
     * INTERNAL:
     * Removes an entity from the identity map. This effectively detaches the
     * entity from the persistence management of ModernORM.
     *
     * @throws ORMInvalidArgumentException
     *
     * @ignore
     */
    public function removeFromIdentityMap(object $entity): bool
    {
        $oid           = spl_object_id($entity);
        $classMetadata = $this->em->getClassMetadata($entity::class);
        $idHash        = self::getIdHashByIdentifier($this->entityStateManager->identifiers[$oid]);

        if ($idHash === '') {
            throw ORMInvalidArgumentException::entityHasNoIdentity($entity, 'remove from identity map');
        }

        $className = $classMetadata->name;

        if ($this->identityMap->remove($className, $idHash, $entity)) {
            $this->lazyReferenceTracker->forget($entity);
            $this->changeTrackingCoordinator->forgetReadOnly($oid);
            $this->em->getProxyFactory()->getBatchQueue()->remove($entity);
            $this->lazyCollectionQueue->removeOwner($entity);

            //$this->entityStateManager->states[$oid] = self::STATE_DETACHED;

            return true;
        }

        return false;
    }

    /**
     * INTERNAL:
     * Gets an entity in the identity map by its identifier hash.
     *
     * @ignore
     */
    public function getByIdHash(string $idHash, string $rootClassName): object|null
    {
        return $this->identityMap->get($rootClassName, $idHash);
    }

    /**
     * INTERNAL:
     * Tries to get an entity by its identifier hash. If no entity is found for
     * the given hash, FALSE is returned.
     *
     * @param mixed $idHash (must be possible to cast it to string)
     *
     * @return false|object The found entity or FALSE.
     *
     * @ignore
     */
    public function tryGetByIdHash(mixed $idHash, string $rootClassName): object|false
    {
        $stringIdHash = (string) $idHash;

        return $this->identityMap->get($rootClassName, $stringIdHash) ?? false;
    }

    /**
     * Checks whether an entity is registered in the identity map of this UnitOfWork.
     */
    public function isInIdentityMap(object $entity): bool
    {
        $oid = spl_object_id($entity);

        if (empty($this->entityStateManager->identifiers[$oid])) {
            return false;
        }

        $classMetadata = $this->em->getClassMetadata($entity::class);
        $idHash        = self::getIdHashByIdentifier($this->entityStateManager->identifiers[$oid]);

        return $this->identityMap->contains($classMetadata->name, $idHash, $entity);
    }

    /** Persists an entity and its cascade-persist graph. */
    public function persist(object $entity): void
    {
        $this->cascadeProcessor->persist($entity, $this->persistEntity(...));
    }

    /** Apply the persist transition to one node before cascade traversal continues. */
    private function persistEntity(object $entity): void
    {
        $oid = spl_object_id($entity);

        $class = $this->em->getClassMetadata($entity::class);

        // We assume NEW, so DETACHED entities result in an exception on flush (constraint violation).
        // If we would detect DETACHED here we would throw an exception anyway with the same
        // consequences (not recoverable/programming error), so just assuming NEW here
        // lets us avoid some database lookups for entities with natural identifiers.
        $entityState = $this->getEntityState($entity, self::STATE_NEW);

        switch ($entityState) {
            case self::STATE_MANAGED:
                // Nothing to do, except if policy is "deferred explicit"
                if ($class->isChangeTrackingDeferredExplicit()) {
                    $this->scheduleForDirtyCheck($entity);
                }

                break;

            case self::STATE_NEW:
                $this->persistNew($class, $entity);
                break;

            case self::STATE_REMOVED:
                // Entity becomes managed again
                $this->flushQueues->removeDeletion($oid);
                $this->addToIdentityMap($entity);

                $this->entityStateManager->states[$oid] = self::STATE_MANAGED;

                if ($class->isChangeTrackingDeferredExplicit()) {
                    $this->scheduleForDirtyCheck($entity);
                }

                break;

            case self::STATE_DETACHED:
                // Can actually not happen right now since we assume STATE_NEW.
                throw ORMInvalidArgumentException::detachedEntityCannot($entity, 'persisted');

            default:
                throw new UnexpectedValueException(sprintf(
                    'Unexpected entity state: %s. %s',
                    $entityState,
                    self::objToStr($entity),
                ));
        }
    }

    private bool $hardDelete = false;

    /** @internal Physical deletion, including ordinary remove cascades. */
    public function hardRemove(object $entity): void
    {
        $previous = $this->hardDelete;
        $this->hardDelete = true;
        try {
            $this->remove($entity);
        } finally {
            $this->hardDelete = $previous;
        }
    }

    public function remove(object $entity): void
    {
        $this->cascadeProcessor->remove($entity, $this->trySoftDelete(...), $this->removeEntity(...));
    }

    /** Soft deletion stops remove cascades at this entity. */
    private function trySoftDelete(object $entity): bool
    {
        $oid = spl_object_id($entity);

        $class = $this->em->getClassMetadata($entity::class);
        if ($class->softDeleteField !== null && ! $this->hardDelete) {
            $state = $this->getEntityState($entity);
            if ($state === self::STATE_DETACHED) {
                throw ORMInvalidArgumentException::detachedEntityCannot($entity, 'removed');
            }
            if ($this->flushQueues->hasInsertion($oid)) {
                $this->scheduleForDelete($entity);
            } elseif ($state === self::STATE_MANAGED && $class->getFieldValue($entity, $class->softDeleteField) === null) {
                if ($this->isReadOnly($entity)) {
                    throw new \LogicException('SoftDelete cannot update an entity marked read-only in the UnitOfWork.');
                }
                $class->setFieldValue($entity, $class->softDeleteField, new \DateTimeImmutable());
                $this->scheduleForDirtyCheck($entity);
            }
            return true;
        }

        return false;
    }

    private function removeEntity(object $entity): void
    {
        $class       = $this->em->getClassMetadata($entity::class);
        $entityState = $this->getEntityState($entity);

        switch ($entityState) {
            case self::STATE_NEW:
            case self::STATE_REMOVED:
                // nothing to do
                break;

            case self::STATE_MANAGED:
                $invoke = $this->listenersInvoker->getSubscribedSystems($class, Events::preRemove);

                if ($invoke !== ListenersInvoker::INVOKE_NONE) {
                    $this->listenersInvoker->invoke($class, Events::preRemove, $entity, new PreRemoveEventArgs($entity, $this->em), $invoke);
                }

                $this->scheduleForDelete($entity);
                break;

            case self::STATE_DETACHED:
                throw ORMInvalidArgumentException::detachedEntityCannot($entity, 'removed');

            default:
                throw new UnexpectedValueException(sprintf(
                    'Unexpected entity state: %s. %s',
                    $entityState,
                    self::objToStr($entity),
                ));
        }
    }

    /** Detach only the given entity, cancelling its pending entity operations. */
    public function detach(object $entity): void
    {
        $oid = spl_object_id($entity);

        switch ($this->getEntityState($entity, self::STATE_DETACHED)) {
            case self::STATE_MANAGED:
            case self::STATE_REMOVED:
                if ($this->isInIdentityMap($entity)) {
                    $this->removeFromIdentityMap($entity);
                }

                $this->flushQueues->removeEntity($oid);
                $this->lazyReferenceTracker->forget($entity);
                $this->entityStateManager->forgetAfterDetach($oid);
                break;
            case self::STATE_NEW:
            case self::STATE_DETACHED:
                return;
        }
    }

    /**
     * Refreshes the state of the given entity from the database, overwriting
     * any local, unpersisted changes.
     *
     * @phpstan-param LockMode::*|null $lockMode
     *
     * @throws InvalidArgumentException If the entity is not MANAGED.
     * @throws TransactionRequiredException
     */
    public function refresh(object $entity, LockMode|int|null $lockMode = null): void
    {
        switch (true) {
            case $lockMode === LockMode::PESSIMISTIC_READ:
            case $lockMode === LockMode::PESSIMISTIC_WRITE:
                if (! $this->em->getConnection()->isTransactionActive()) {
                    throw TransactionRequiredException::transactionRequired();
                }
        }

        $oid = spl_object_id($entity);

        $class = $this->em->getClassMetadata($entity::class);

        $state = $this->getEntityState($entity);
        if ($state !== self::STATE_MANAGED) {
            throw ORMInvalidArgumentException::entityNotManaged($entity);
        }

        $this->getEntityPersister($class->name)->refresh(
            array_combine($class->getIdentifierFieldNames(), $this->entityStateManager->identifiers[$oid]),
            $entity,
            $lockMode,
        );
    }

    /**
     * Acquire a lock on the given entity.
     *
     * @phpstan-param LockMode::* $lockMode
     *
     * @throws ORMInvalidArgumentException
     * @throws TransactionRequiredException
     * @throws OptimisticLockException
     */
    public function lock(object $entity, LockMode|int $lockMode, DateTimeInterface|int|null $lockVersion = null): void
    {
        if ($this->getEntityState($entity, self::STATE_DETACHED) !== self::STATE_MANAGED) {
            throw ORMInvalidArgumentException::entityNotManaged($entity);
        }

        $class = $this->em->getClassMetadata($entity::class);

        switch (true) {
            case $lockMode === LockMode::OPTIMISTIC:
                if (! $class->isVersioned) {
                    throw OptimisticLockException::notVersioned($class->name);
                }

                if ($lockVersion === null) {
                    return;
                }

                $this->initializeObject($entity);

                assert($class->versionField !== null);
                $entityVersion = $class->propertyAccessors[$class->versionField]->getValue($entity);

                // phpcs:ignore SlevomatCodingStandard.Operators.DisallowEqualOperators.DisallowedNotEqualOperator
                if ($entityVersion != $lockVersion) {
                    throw OptimisticLockException::lockFailedVersionMismatch($entity, $lockVersion, $entityVersion);
                }

                break;

            case $lockMode === LockMode::PESSIMISTIC_READ:
            case $lockMode === LockMode::PESSIMISTIC_WRITE:
                if (! $this->em->getConnection()->isTransactionActive()) {
                    throw TransactionRequiredException::transactionRequired();
                }

                $oid = spl_object_id($entity);

                $this->getEntityPersister($class->name)->lock(
                    array_combine($class->getIdentifierFieldNames(), $this->entityStateManager->identifiers[$oid]),
                    $lockMode,
                );
                break;

            default:
                // Do nothing
        }
    }

    /**
     * Clears the UnitOfWork.
     */
    public function clear(): void
    {
        $this->em->getProxyFactory()->getBatchQueue()->clear();
        $this->lazyCollectionQueue->clear();
        $this->flushQueues->clear();
        $this->identityMap->clear();
        $this->lazyReferenceTracker->clear();
        // Preserve reset order: clearing tracking maps can release user objects.
        $this->changeTrackingCoordinator->clearReadOnlyMarks();
        $this->changeTrackingCoordinator->clearPendingChecks();
        $this->entityStateManager->states = [];
        $this->changeTrackingCoordinator->clearChangeSets();
        $this->entityStateManager->snapshots = [];
        $this->entityStateManager->identifiers = [];

        $this->eagerLoadCoordinator->clear();

        $this->associationChangePlanner->clear();
        $this->eventDispatcher->dispatchEvent(Events::onClear, new OnClearEventArgs($this->em));
    }

    /**
     * INTERNAL:
     * Schedules an orphaned entity for removal. The remove() operation will be
     * invoked on that entity at the beginning of the next commit of this
     * UnitOfWork.
     *
     * @ignore
     */
    public function scheduleOrphanRemoval(object $entity, ?PersistentCollection $origin = null): void
    {
        $this->associationChangePlanner->schedule(
            $entity,
            $origin === null ? OrphanRemovalReason::EXPLICIT : OrphanRemovalReason::COLLECTION_MUTATION,
            $origin,
        );
    }

    /** @return \Generator<int, AssociationMembership> */
    private function observeOrphanMemberships(object $entity): \Generator
    {
        foreach ($this->associationChangePlanner->originsFor($entity) as $origin) {
            assert($origin instanceof PersistentCollection);
            $owner = $origin->getOwner();
            $mapping = $origin->getMapping();
            if ($owner === null) {
                continue;
            }
            $source = $this->em->getClassMetadata($mapping->sourceEntity);
            $current = $source->propertyAccessors[$mapping->fieldName]->getValue($owner);
            if ($current instanceof Collection && $current->contains($entity)) {
                yield new AssociationMembership($owner, true);
                continue;
            }
            $owningSideOwner = null;
            if ($mapping->isOneToMany()) {
                $target = $this->em->getClassMetadata($mapping->targetEntity);
                $owningSideOwner = $target->propertyAccessors[$mapping->mappedBy]->getValue($entity);
            }
            yield new AssociationMembership($owner, false, $owningSideOwner);
        }
    }

    /**
     * INTERNAL:
     * Cancels a previously scheduled orphan removal.
     *
     * @ignore
     */
    public function cancelOrphanRemoval(object $entity): void
    {
        $this->associationChangePlanner->cancel($entity);
    }

    /**
     * INTERNAL:
     * Schedules a complete collection for removal when this UnitOfWork commits.
     */
    public function scheduleCollectionDeletion(PersistentCollection $coll): void
    {
        $this->flushQueues->scheduleCollectionDeletion($coll);
    }

    public function isCollectionScheduledForDeletion(PersistentCollection $coll): bool
    {
        return $this->flushQueues->hasCollectionDeletion(spl_object_id($coll));
    }

    /**
     * INTERNAL:
     * Creates an entity. Used for reconstitution of persistent entities.
     *
     * Internal note: Highly performance-sensitive method.
     *
     * @param class-string         $className The name of the entity class.
     * @param mixed[]              $data      The data for the entity.
     * @param array<string, mixed> $hints     Any hints to account for during reconstitution/lookup of the entity.
     *
     * @return object The managed entity instance.
     *
     * @ignore
     * @todo Rename: getOrCreateEntity
     */
    public function createEntity(string $className, array $data, array &$hints = []): object
    {
        $class = $this->em->getClassMetadata($className);

        $id     = $this->identifierFlattener->flattenIdentifier($class, $data);
        $idHash = self::getIdHashByIdentifier($id);

        $managedEntity = $this->identityMap->get($class->name, $idHash);
        if ($managedEntity !== null) {
            $entity = $managedEntity;
            $oid    = spl_object_id($entity);

            if (
                isset($hints[Query::HINT_REFRESH], $hints[Query::HINT_REFRESH_ENTITY])
            ) {
                $unmanagedProxy = $hints[Query::HINT_REFRESH_ENTITY];
                if (
                    $unmanagedProxy !== $entity
                    && $this->isIdentifierEquals($unmanagedProxy, $entity)
                ) {
                    // We will hydrate the given un-managed proxy anyway:
                    // continue work, but consider it the entity from now on
                    $entity = $unmanagedProxy;
                }
            }

            if ($this->isUninitializedObject($entity)) {
                $class->reflClass->markLazyObjectAsInitialized($entity);
            } elseif (
                ($hints[\ModernORM\Batch\LazyReferenceQueue::HINT_TRIGGER] ?? null) !== $entity
                && (! isset($hints[Query::HINT_REFRESH])
                    || (isset($hints[Query::HINT_REFRESH_ENTITY]) && $hints[Query::HINT_REFRESH_ENTITY] !== $entity))
            ) {
                return $entity;
            }

            $this->entityStateManager->snapshots[$oid] = $data;
            $this->lazyReferenceTracker->forget($managedEntity);
        } else {
            $entity = $class->newInstance();

            $this->statistics?->entityCreatedDuringHydration();
            $oid = spl_object_id($entity);
            $this->registerManaged($entity, $id, $data);

            if (isset($hints[Query::HINT_READ_ONLY]) && $hints[Query::HINT_READ_ONLY] === true) {
                $this->changeTrackingCoordinator->markReadOnly($entity);
            }
        }

        $this->entityHydrator->hydrate($class, $entity, $data, $hints, $managedEntity, $idHash);

        // defer invoking of postLoad event to hydration complete step
        $this->hydrationCompleteHandler->deferPostLoadInvoking($class, $entity);

        return $entity;
    }

    public function triggerEagerLoads(): void
    {
        $this->eagerLoadCoordinator->trigger();
    }

    /**
     * Initializes (loads) an uninitialized persistent collection of an entity.
     *
     * @todo Maybe later move to EntityManager#initialize($proxyOrCollection). See DDC-733.
     */
    public function loadCollection(PersistentCollection $collection): void
    {
        $assoc     = $collection->getMapping();
        $persister = $this->getEntityPersister($assoc->targetEntity);

        switch ($assoc->type()) {
            case ClassMetadata::ONE_TO_MANY:
                $persister->loadOneToManyCollection($assoc, $collection->getOwner(), $collection);
                break;

            case ClassMetadata::MANY_TO_MANY:
                $persister->loadManyToManyCollection($assoc, $collection->getOwner(), $collection);
                break;
        }

        $collection->setInitialized(true);
    }

    /**
     * Gets the identity map of the UnitOfWork.
     *
     * @return array<class-string, array<string, object>>
     */
    public function getIdentityMap(): array
    {
        return $this->identityMap->all();
    }

    /** @internal Hydration must not overwrite a locally changed to-one, even after changeset computation. */
    public function isToOneAssociationChanged(object $entity, string $field): bool
    {
        return $this->changeTrackingCoordinator->isToOneAssociationChanged($entity, $field);
    }

    /** @internal A targeted lazy initialization/refresh does not refresh unrelated managed entities.
     *  @param array<string, mixed> $hints
     */
    public function isHydrationRefresh(object $entity, array $hints): bool
    {
        return isset($hints[Query::HINT_REFRESH])
            && (! isset($hints[Query::HINT_REFRESH_ENTITY]) || $hints[Query::HINT_REFRESH_ENTITY] === $entity);
    }

    /** @internal Complete a bidirectional hydration link without discarding pending local changes. */
    public function hydrateToOneAssociation(object $entity, string $field, ?object $value, bool $refresh = false): void
    {
        if (! $refresh && $this->isToOneAssociationChanged($entity, $field)) {
            return;
        }
        $this->em->getClassMetadata($entity::class)->propertyAccessors[$field]->setValue($entity, $value);
        $this->entityStateManager->snapshots[spl_object_id($entity)][$field] = $value;
    }

    /**
     * Gets the original data of an entity. The original data is the data that was
     * present at the time the entity was reconstituted from the database.
     *
     * @phpstan-return array<string, mixed>
     */
    public function getOriginalEntityData(object $entity): array
    {
        $oid = spl_object_id($entity);

        return $this->entityStateManager->snapshots[$oid] ?? [];
    }

    /**
     * @param mixed[] $data
     *
     * @ignore
     */
    public function setOriginalEntityData(object $entity, array $data): void
    {
        $this->entityStateManager->snapshots[spl_object_id($entity)] = $data;
    }

    /**
     * INTERNAL:
     * Sets a property value of the original data array of an entity.
     *
     * @ignore
     */
    public function setOriginalEntityProperty(int $oid, string $property, mixed $value): void
    {
        $this->entityStateManager->snapshots[$oid][$property] = $value;
    }

    /**
     * Gets the identifier of an entity.
     * The returned array contains the entity's single scalar identifier, keyed by field name.
     *
     * @return mixed[] The identifier values.
     */
    public function getEntityIdentifier(object $entity): array
    {
        return $this->entityStateManager->identifiers[spl_object_id($entity)]
            ?? throw EntityNotFoundException::noIdentifierFound(get_debug_type($entity));
    }

    /**
     * Processes an entity instance to extract their identifier values.
     *
     * @return mixed A scalar value.
     *
     * @throws ORMInvalidArgumentException
     */
    public function getSingleIdentifierValue(object $entity): mixed
    {
        $class = $this->em->getClassMetadata($entity::class);

        $values = $this->isInIdentityMap($entity)
            ? $this->getEntityIdentifier($entity)
            : $class->getIdentifierValues($entity);

        return $values[$class->identifier[0]] ?? null;
    }

    /**
     * Tries to find an entity with the given identifier in the identity map of
     * this UnitOfWork.
     *
     * @param mixed        $id            The entity identifier to look for.
     * @param class-string $rootClassName The mapped entity class name.
     *
     * @return object|false Returns the entity with the specified identifier if it exists in
     *                      this UnitOfWork, FALSE otherwise.
     */
    public function tryGetById(mixed $id, string $rootClassName): object|false
    {
        $idHash = self::getIdHashByIdentifier((array) $id);

        return $this->identityMap->get($rootClassName, $idHash) ?? false;
    }

    /**
     * Schedules an entity for dirty-checking at commit-time.
     *
     * @todo Rename: scheduleForSynchronization
     */
    public function scheduleForDirtyCheck(object $entity): void
    {
        $this->changeTrackingCoordinator->scheduleForDirtyCheck($entity);
    }

    /**
     * Checks whether the UnitOfWork has any pending insertions.
     */
    public function hasPendingInsertions(): bool
    {
        return ! empty($this->flushQueues->insertions());
    }

    /**
     * Calculates the size of the UnitOfWork. The size of the UnitOfWork is the
     * number of entities in the identity map.
     */
    public function size(): int
    {
        return $this->identityMap->size();
    }

    /**
     * Gets the EntityPersister for an Entity.
     *
     * @param class-string $entityName The name of the Entity.
     */
    public function getEntityPersister(string $entityName): EntityPersister
    {
        return $this->persisterRegistry->getEntityPersister($entityName);
    }

    /** Gets a collection persister for a collection-valued association. */
    public function getCollectionPersister(AssociationMapping $association): CollectionPersister
    {
        return $this->persisterRegistry->getCollectionPersister($association);
    }

    /**
     * INTERNAL:
     * Registers an entity as managed.
     *
     * @param mixed[] $id   The identifier values.
     * @param mixed[] $data The original entity data.
     */
    public function registerManaged(object $entity, array $id, array $data): void
    {
        $this->entityStateManager->registerManaged($entity, $id, $data);
    }

    /**
     * INTERNAL:
     * Registers an uninitialized reference/association proxy as managed,
     * i.e. an entity obtained via {@see \ModernORM\Proxy\ProxyFactory::getProxy()}
     * of which no field has been loaded yet.
     *
     * @param mixed[] $id The identifier values.
     */
    public function registerManagedProxy(object $entity, array $id): void
    {
        $this->registerManaged($entity, $id, []);
        $this->lazyReferenceTracker->mark($entity);
    }

    /* PropertyChangedListener implementation */

    /**
     * Notifies this UnitOfWork of a property change in an entity.
     *
     * {@inheritDoc}
     */
    public function propertyChanged(object $sender, string $propertyName, mixed $oldValue, mixed $newValue): void
    {
        $this->changeTrackingCoordinator->propertyChanged($sender, $propertyName, $oldValue, $newValue);
    }

    /**
     * Gets the currently scheduled entity insertions in this UnitOfWork.
     *
     * @phpstan-return array<int, object>
     */
    public function getScheduledEntityInsertions(): array
    {
        return $this->flushQueues->insertions();
    }

    /**
     * Gets the currently scheduled entity updates in this UnitOfWork.
     *
     * @phpstan-return array<int, object>
     */
    public function getScheduledEntityUpdates(): array
    {
        return $this->flushQueues->updates();
    }

    /**
     * Gets the currently scheduled entity deletions in this UnitOfWork.
     *
     * @phpstan-return array<int, object>
     */
    public function getScheduledEntityDeletions(): array
    {
        return $this->flushQueues->deletions();
    }

    /**
     * Gets the currently scheduled complete collection deletions
     *
     * @phpstan-return array<int, PersistentCollection<array-key, object>>
     */
    public function getScheduledCollectionDeletions(): array
    {
        return $this->flushQueues->collectionDeletions();
    }

    /**
     * Gets the currently scheduled collection inserts, updates and deletes.
     *
     * @phpstan-return array<int, PersistentCollection<array-key, object>>
     */
    public function getScheduledCollectionUpdates(): array
    {
        return $this->flushQueues->collectionUpdates();
    }

    /**
     * Helper method to initialize a lazy loading proxy or persistent collection.
     */
    public function initializeObject(object $obj): void
    {
        if ($obj instanceof PersistentCollection) {
            $obj->initialize();

            return;
        }

        if (! $this->isMappedClass($obj::class)) {
            return;
        }

        $reflection = $this->em->getClassMetadata($obj::class)->getReflectionClass();
        $reflection->initializeLazyObject($obj);
    }

    /** Tests if a value is an uninitialized entity. */
    public function isUninitializedObject(mixed $obj): bool
    {
        if (! ($obj instanceof Collection) && is_object($obj)) {
            if (! $this->isMappedClass($obj::class)) {
                return false;
            }

            return $this->em->getClassMetadata($obj::class)->reflClass->isUninitializedLazyObject($obj);
        }

        return false;
    }

    /**
     * Helper method to show an object as string.
     */
    private static function objToStr(object $obj): string
    {
        return $obj instanceof Stringable ? (string) $obj : get_debug_type($obj) . '@' . spl_object_id($obj);
    }

    /**
     * Marks an entity as read-only so that it will not be considered for updates during UnitOfWork#commit().
     *
     * This operation cannot be undone as some parts of the UnitOfWork now keep gathering information
     * on this object that might be necessary to perform a correct update.
     *
     * @throws ORMInvalidArgumentException
     */
    public function markReadOnly(object $object): void
    {
        if (! $this->isInIdentityMap($object)) {
            throw ORMInvalidArgumentException::readOnlyRequiresManagedEntity($object);
        }

        $this->changeTrackingCoordinator->markReadOnly($object);
    }

    /**
     * Is this entity read only?
     *
     * @throws ORMInvalidArgumentException
     */
    public function isReadOnly(object $object): bool
    {
        return $this->changeTrackingCoordinator->isMarkedReadOnly($object)
            || ($this->isMappedClass($object::class) && $this->em->getClassMetadata($object::class)->isReadOnly);
    }

    private function dispatchPreFlushEvent(): void
    {
        $this->eventDispatcher->dispatchEvent(Events::preFlush, new PreFlushEventArgs($this->em));
    }

    private function dispatchOnFlushEvent(): void
    {
        $this->eventDispatcher->dispatchEvent(Events::onFlush, new OnFlushEventArgs($this->em));
    }

    private function dispatchPostFlushEvent(): void
    {
        $this->eventDispatcher->dispatchEvent(Events::postFlush, new PostFlushEventArgs($this->em));
    }

    /**
     * Whether this entity manager has, or can load, metadata for the class.
     *
     * isTransient() alone is not enough: by contract it is only false for entities and mapped
     * superclasses, while embeddables also have metadata and can be lazy ghosts. Checking the
     * already loaded metadata first covers those and keeps the common case an array lookup.
     *
     * @phpstan-param class-string $className
     */
    private function isMappedClass(string $className): bool
    {
        $metadataFactory = $this->em->getMetadataFactory();

        return $metadataFactory->hasMetadataFor($className) || ! $metadataFactory->isTransient($className);
    }

    /**
     * Verifies if two given entities actually are the same based on identifier comparison
     */
    private function isIdentifierEquals(object $entity1, object $entity2): bool
    {
        if ($entity1 === $entity2) {
            return true;
        }

        $class = $this->em->getClassMetadata($entity1::class);

        if ($class !== $this->em->getClassMetadata($entity2::class)) {
            return false;
        }

        $oid1 = spl_object_id($entity1);
        $oid2 = spl_object_id($entity2);

        $id1 = $this->entityStateManager->identifiers[$oid1] ?? $this->identifierFlattener->flattenIdentifier($class, $class->getIdentifierValues($entity1));
        $id2 = $this->entityStateManager->identifiers[$oid2] ?? $this->identifierFlattener->flattenIdentifier($class, $class->getIdentifierValues($entity2));

        return $id1 === $id2 || self::getIdHashByIdentifier($id1) === self::getIdHashByIdentifier($id2);
    }

    /**
     * This method called by hydrators, and indicates that hydrator totally completed current hydration cycle.
     * Unit of work able to fire deferred events, related to loading events here.
     *
     * @internal should be called internally from object hydrators
     */
    public function hydrationComplete(): void
    {
        $this->hydrationCompleteHandler->hydrationComplete();
    }

    /** @throws MappingException if the entity has more than a single identifier. */
    private function convertSingleFieldIdentifierToPHPValue(ClassMetadata $class, mixed $identifierValue): mixed
    {
        return $this->em->getConnection()->convertToPHPValue(
            $identifierValue,
            $class->getTypeOfField($class->getSingleIdentifierFieldName()),
        );
    }

    /**
     * Assign a post-insert generated ID to an entity
     *
     * This is used by EntityPersisters after they inserted entities into the database.
     * It will place the assigned ID values in the entity's fields and start tracking
     * the entity in the identity map.
     */
    final public function assignPostInsertId(object $entity, mixed $generatedId): void
    {
        $class   = $this->em->getClassMetadata($entity::class);
        $idField = $class->getSingleIdentifierFieldName();
        $idValue = $this->convertSingleFieldIdentifierToPHPValue($class, $generatedId);

        $class->propertyAccessors[$idField]->setValue($entity, $idValue);
        $this->entityStateManager->registerPostInsertId($entity, $idField, $idValue);
    }
}
