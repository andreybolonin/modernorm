<?php

declare(strict_types=1);

namespace ModernORM;

use ModernORM\Exception\ORMInvalidArgumentException;
use ModernORM\Exception\OptimisticLockException;
use ModernORM\Exception\TransactionRequiredException;
use BackedEnum;
use DateTimeInterface;
use Doctrine\Common\EventManager;
use Doctrine\Common\EventManagerInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\Deprecations\Deprecation;
use ModernORM\Exception\EntityManagerClosed;
use ModernORM\Exception\InvalidHydrationMode;
use ModernORM\Exception\MissingIdentifierField;
use ModernORM\Exception\MissingMappingDriverImplementation;
use ModernORM\Exception\ORMException;
use ModernORM\Exception\UnrecognizedIdentifierFields;
use ModernORM\Internal\Hydration\AbstractHydrator;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\ClassMetadataFactory;
use ModernORM\Proxy\ProxyFactory;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder as Expr;
use ModernORM\Query\ResultSetMapping;
use ModernORM\Repository\RepositoryFactory;

use function array_keys;
use function is_array;
use function ltrim;
use function method_exists;

/**
 * The EntityManager is the central access point to ORM functionality.
 *
 * It is a facade to all different ORM subsystems such as UnitOfWork,
 * Query Language and Repository API. The quickest way to obtain a fully
 * configured EntityManager is:
 *
 *     use ModernORM\ORMSetup;
 *     use Doctrine\DBAL\DriverManager;
 *     use ModernORM\EntityManager;
 *
 *     $paths = ['/path/to/entity/mapping/files'];
 *
 *     $config = ORMSetup::createAttributeMetadataConfig($paths);
 *     $connection = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'dbname' => 'app']);
 *     $entityManager = new EntityManager($connection, $config);
 *
 * You should never attempt to inherit from the EntityManager: Inheritance
 * is not a valid extension point for the EntityManager. Instead you
 * should take a look at the {@see \ModernORM\Decorator\EntityManagerDecorator}
 * and wrap your entity manager in a decorator.
 *
 * @final
 */
class EntityManager implements EntityManagerInterface
{
    /**
     * The metadata factory, used to retrieve the ORM metadata of entity classes.
     */
    private ClassMetadataFactory $metadataFactory;

    /**
     * The UnitOfWork used to coordinate object-level transactions.
     */
    private UnitOfWork $unitOfWork;

    /**
     * The event manager that is the central point of the event system.
     */
    private EventManager $eventManager;

    /**
     * The proxy factory used to create dynamic proxies.
     */
    private ProxyFactory $proxyFactory;

    /**
     * The repository factory used to create dynamic repositories.
     */
    private RepositoryFactory $repositoryFactory;

    /**
     * The expression builder instance used to generate query expressions.
     */
    private Expr|null $expressionBuilder = null;

    /**
     * Whether the EntityManager is closed or not.
     */
    private bool $closed = false;

    /**
     * Collection of query filters.
     */

    /**
     * The second level cache regions API.
     */
    private Cache|null $cache = null;

    private ?Statistics $statistics = null;
    private bool $flushing = false;

    /**
     * Creates a new EntityManager that operates on the given database connection
     * and uses the given Configuration and EventManager implementations.
     *
     * @param Connection $conn The database connection used by the EntityManager.
     */
    public function __construct(
        private Connection $conn,
        private Configuration $config,
        EventManager|null $eventManager = null,
    ) {
        foreach ($conn->getConfiguration()->getMiddlewares() as $middleware) {
            if (! $middleware instanceof \ModernORM\Statistics\Middleware) {
                continue;
            }
            if ($this->statistics !== null || ! $middleware->getStatistics()->isDriverBound()) {
                throw new \LogicException('Configure exactly one statistics middleware before creating the DBAL connection.');
            }
            $this->statistics = $middleware->getStatistics();
        }

        if (! $conn->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
            throw new \InvalidArgumentException('ModernORM supports PostgreSQL only.');
        }

        if (! $config->getMetadataDriverImpl()) {
            throw MissingMappingDriverImplementation::create();
        }

        $this->eventManager = $eventManager
            ?? (
                method_exists($conn, 'getEventManager')
                ? $conn->getEventManager()
                : new EventManager()
            );

        $metadataFactoryClassName = $config->getClassMetadataFactoryName();

        $this->metadataFactory = new $metadataFactoryClassName();
        $this->metadataFactory->setEntityManager($this);

        $this->configureMetadataCache();

        $this->repositoryFactory = $config->getRepositoryFactory();
        $this->unitOfWork        = new UnitOfWork($this);
        $this->proxyFactory = new ProxyFactory($this);

        if ($config->isSecondLevelCacheEnabled()) {
            $cacheConfig  = $config->getSecondLevelCacheConfiguration();
            if ($cacheConfig === null) {
                throw new \InvalidArgumentException('L2 requires an explicitly configured PSR-6 pool.');
            }
            $this->cache = new Cache($this, $cacheConfig);
        }
    }

    public function getConnection(): Connection
    {
        return $this->conn;
    }

    public function bulk(): \ModernORM\Bulk\BulkExecutor
    {
        return new \ModernORM\Bulk\BulkExecutor($this);
    }

    /** Connection-scoped statistics, or null when its DBAL middleware was not installed. */
    public function getStatistics(): ?Statistics
    {
        return $this->statistics;
    }

    public function getMetadataFactory(): ClassMetadataFactory
    {
        return $this->metadataFactory;
    }

    public function getExpressionBuilder(): Expr
    {
        return $this->expressionBuilder ??= $this->conn->createExpressionBuilder();
    }

    public function beginTransaction(): void
    {
        $this->conn->beginTransaction();
    }

    public function getCache(): Cache|null
    {
        return $this->cache;
    }

    public function wrapInTransaction(callable $func): mixed
    {
        $this->conn->beginTransaction();

        $successful = false;

        try {
            $return = $func($this);

            $this->flush();
            $this->conn->commit();

            $successful = true;

            return $return;
        } finally {
            if (! $successful) {
                $this->close();
                if ($this->conn->isTransactionActive()) {
                    $this->conn->rollBack();
                }
            }
        }
    }

    public function commit(): void
    {
        $this->conn->commit();
    }

    public function rollback(): void
    {
        $this->conn->rollBack();
    }

    /**
     * Returns the ORM metadata descriptor for a class.
     *
     * Internal note: Performance-sensitive method.
     *
     * {@inheritDoc}
     */
    public function getClassMetadata(string $className): Mapping\ClassMetadata
    {
        return $this->metadataFactory->getMetadataFor($className);
    }

    public function createQuery(string $sql, ResultSetMapping $rsm): Query
    {
        $query = new Query($this);

        $query->setSQL($sql);
        $query->setResultSetMapping($rsm);

        return $query;
    }

    public function createNativeQuery(string $sql, ResultSetMapping $rsm): NativeQuery
    {
        $query = new NativeQuery($this);

        $query->setSQL($sql);
        $query->setResultSetMapping($rsm);

        return $query;
    }

    public function createQueryBuilder(): QueryBuilder
    {
        return new QueryBuilder($this);
    }

    /**
     * Flushes all changes to objects that have been queued up to now to the database.
     * This effectively synchronizes the in-memory state of managed objects with the
     * database.
     *
     * If an entity is explicitly passed to this method only this entity and
     * the cascade-persist semantics + scheduled inserts/removals are synchronized.
     *
     * @throws OptimisticLockException If a version check on an entity that
     * makes use of optimistic locking fails.
     * @throws ORMException
     */
    public function flush(): void
    {
        if ($this->flushing) {
            throw new \LogicException('Nested flush() is not supported.');
        }
        $this->flushing = true;
        $measurement = $this->statistics?->start();
        $failed = true;
        try {
            $this->errorIfClosed();
            $this->unitOfWork->commit();
            $failed = false;
        } finally {
            $this->flushing = false;
            if ($measurement !== null) {
                $this->statistics->recordFlush($measurement, $failed);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function find($className, mixed $id, LockMode|int|null $lockMode = LockMode::NONE, int|null $lockVersion = null): object|null
    {
        if ($lockMode === null) {
            Deprecation::trigger(
                'doctrine/orm',
                'https://github.com/doctrine/orm/pull/12548',
                'Passing null as lock mode to %s() is deprecated and will not be possible in Doctrine ORM 4.0, pass LockMode::NONE instead.',
                __METHOD__,
            );

            $lockMode = LockMode::NONE;
        }

        $class = $this->metadataFactory->getMetadataFor(ltrim($className, '\\'));

        $this->checkLockRequirements($lockMode, $class);

        $sortedId = $this->normalizeIdentifier($class, $id);

        $unitOfWork = $this->getUnitOfWork();

        $entity = $unitOfWork->tryGetById($sortedId, $class->name);

        // Check identity map first
        if ($entity !== false) {
            if (! ($entity instanceof $class->name)) {
                return null;
            }

            if ($class->softDeleteField !== null && $class->getFieldValue($entity, $class->softDeleteField) !== null) {
                return null;
            }

            switch (true) {
                case $lockMode === LockMode::OPTIMISTIC:
                    $this->lock($entity, $lockMode, $lockVersion);
                    break;

                case $lockMode === LockMode::PESSIMISTIC_READ:
                case $lockMode === LockMode::PESSIMISTIC_WRITE:
                    $persister = $unitOfWork->getEntityPersister($class->name);
                    $persister->refresh($sortedId, $entity, $lockMode);
                    break;
            }

            return $entity; // Hit!
        }

        $persister = $unitOfWork->getEntityPersister($class->name);

        switch (true) {
            case $lockMode === LockMode::OPTIMISTIC:
                $entity = $persister->load($sortedId);

                if ($entity !== null) {
                    $unitOfWork->lock($entity, $lockMode, $lockVersion);
                }

                return $entity;

            case $lockMode === LockMode::PESSIMISTIC_READ:
            case $lockMode === LockMode::PESSIMISTIC_WRITE:
                return $persister->load($sortedId, null, null, [], $lockMode);

            default:
                return $persister->loadById($sortedId);
        }
    }

    /** Normalize the scalar PK; a one-field named array is accepted by persistence APIs. */
    private function normalizeIdentifier(ClassMetadata $class, mixed $id): array
    {
        $field = $class->getSingleIdentifierFieldName();
        if (is_array($id)) {
            if (! isset($id[$field])) {
                throw MissingIdentifierField::fromFieldAndClass($field, $class->name);
            }
            $extra = array_diff_key($id, [$field => true]);
            if ($extra !== []) {
                throw UnrecognizedIdentifierFields::fromClassAndFieldNames($class->name, array_keys($extra));
            }
            $id = $id[$field];
        }
        if ($id instanceof BackedEnum) {
            $id = $id->value;
        }
        if (! is_scalar($id)) {
            throw new \InvalidArgumentException('An entity identifier must be scalar.');
        }

        return [$field => $id];
    }

    public function getReference(string $entityName, mixed $id): object|null
    {
        $class = $this->metadataFactory->getMetadataFor(ltrim($entityName, '\\'));

        if ($class->softDeleteField !== null) {
            return $this->find($class->name, $id);
        }
        $sortedId = $this->normalizeIdentifier($class, $id);

        $entity = $this->unitOfWork->tryGetById($sortedId, $class->name);

        // Check identity map first, if its already in there just return it.
        if ($entity !== false) {
            return $entity instanceof $class->name ? $entity : null;
        }

        $entity = $this->proxyFactory->getProxy($class->name, $sortedId);

        $this->unitOfWork->registerManagedProxy($entity, $sortedId);

        return $entity;
    }

    /**
     * Clears the EntityManager. All entities that are currently managed
     * by this EntityManager become detached.
     */
    public function clear(): void
    {
        $this->unitOfWork->clear();
    }

    public function close(): void
    {
        try {
            $this->clear();
        } finally {
            $this->closed = true;
        }
    }

    /**
     * Tells the EntityManager to make an instance managed and persistent.
     *
     * The entity will be entered into the database at or before transaction
     * commit or as a result of the flush operation.
     *
     * NOTE: The persist operation always considers entities that are not yet known to
     * this EntityManager as NEW. Do not pass detached entities to the persist operation.
     *
     * @throws ORMInvalidArgumentException
     * @throws ORMException
     */
    public function persist(object $object): void
    {
        $this->errorIfClosed();

        $this->unitOfWork->persist($object);
    }

    /** Explicit query-local visibility; raw SQL still follows its own predicates. */
    public function findIncludingDeleted(string $className, mixed $id): ?object
    {
        $class = $this->getClassMetadata($className);
        $identifier = $this->normalizeIdentifier($class, $id);
        $query = $this->createQueryBuilder()->withDeleted()->fromEntity($class->name, 'r');
        $field = $class->getSingleIdentifierFieldName();
        return $query->where($query->column('r', $field) . ' = :id')->setParameter('id', $identifier[$field])->getQuery()->getOneOrNullResult();
    }

    public function restore(object $entity): void
    {
        $this->errorIfClosed();
        $class = $this->getClassMetadata($entity::class);
        if ($class->softDeleteField === null || ! $this->contains($entity)) {
            throw new \InvalidArgumentException('restore() requires a managed SoftDelete entity.');
        }
        if ($this->unitOfWork->isReadOnly($entity)) {
            throw new \LogicException('SoftDelete cannot restore an entity marked read-only in the UnitOfWork.');
        }
        $class->setFieldValue($entity, $class->softDeleteField, null);
        $this->unitOfWork->scheduleForDirtyCheck($entity);
    }

    public function hardDelete(object $entity): void
    {
        $this->errorIfClosed();
        $this->unitOfWork->hardRemove($entity);
    }

    /** Schedule logical deletion for SoftDelete entities, physical deletion otherwise. */
    public function remove(object $object): void
    {
        $this->errorIfClosed();

        $this->unitOfWork->remove($object);
    }

    public function refresh(object $object, LockMode|int|null $lockMode = LockMode::NONE): void
    {
        if ($lockMode === null) {
            Deprecation::trigger(
                'doctrine/orm',
                'https://github.com/doctrine/orm/pull/12548',
                'Passing null as lock mode to %s() is deprecated and will not be possible in Doctrine ORM 4.0, pass LockMode::NONE instead.',
                __METHOD__,
            );

            $lockMode = LockMode::NONE;
        }

        $this->errorIfClosed();

        $this->unitOfWork->refresh($object, $lockMode);
    }

    /**
     * Detaches an entity from the EntityManager, causing a managed entity to
     * become detached.  Unflushed changes made to the entity if any
     * (including removal of the entity), will not be synchronized to the database.
     * Entities which previously referenced the detached entity will continue to
     * reference it.
     *
     * @throws ORMInvalidArgumentException
     */
    public function detach(object $object): void
    {
        $this->unitOfWork->detach($object);
    }

    public function lock(object $entity, LockMode|int $lockMode, DateTimeInterface|int|null $lockVersion = null): void
    {
        $this->unitOfWork->lock($entity, $lockMode, $lockVersion);
    }

    /**
     * Gets the repository for an entity class.
     *
     * @param class-string<T> $className The name of the entity.
     *
     * @return EntityRepository<T> The repository class.
     *
     * @template T of object
     */
    public function getRepository(string $className): EntityRepository
    {
        return $this->repositoryFactory->getRepository($this, $className);
    }

    /**
     * Determines whether an entity instance is managed in this EntityManager.
     *
     * @return bool TRUE if this EntityManager currently manages the given entity, FALSE otherwise.
     */
    public function contains(object $object): bool
    {
        return $this->unitOfWork->isScheduledForInsert($object)
            || $this->unitOfWork->isInIdentityMap($object)
            && ! $this->unitOfWork->isScheduledForDelete($object);
    }

    public function getEventManager(): EventManagerInterface
    {
        return $this->eventManager;
    }

    public function getConfiguration(): Configuration
    {
        return $this->config;
    }

    /**
     * Throws an exception if the EntityManager is closed or currently not active.
     *
     * @throws EntityManagerClosed If the EntityManager is closed.
     */
    private function errorIfClosed(): void
    {
        if ($this->closed) {
            throw EntityManagerClosed::create();
        }
    }

    public function isOpen(): bool
    {
        return ! $this->closed;
    }

    public function getUnitOfWork(): UnitOfWork
    {
        return $this->unitOfWork;
    }

    public function newHydrator(string|int $hydrationMode): AbstractHydrator
    {
        return match ($hydrationMode) {
            Query::HYDRATE_OBJECT => new Internal\Hydration\ObjectHydrator($this),
            Query::HYDRATE_ARRAY => new Internal\Hydration\ArrayHydrator($this),
            Query::HYDRATE_SCALAR => new Internal\Hydration\ScalarHydrator($this),
            Query::HYDRATE_SINGLE_SCALAR => new Internal\Hydration\SingleScalarHydrator($this),
            Query::HYDRATE_SIMPLEOBJECT => new Internal\Hydration\SimpleObjectHydrator($this),
            Query::HYDRATE_SCALAR_COLUMN => new Internal\Hydration\ScalarColumnHydrator($this),
            default => $this->createCustomHydrator((string) $hydrationMode),
        };
    }

    public function getProxyFactory(): ProxyFactory
    {
        return $this->proxyFactory;
    }

    public function initializeObject(object $obj): void
    {
        $this->unitOfWork->initializeObject($obj);
    }

    /**
     * {@inheritDoc}
     */
    public function isUninitializedObject($value): bool
    {
        return $this->unitOfWork->isUninitializedObject($value);
    }

    /**
     * @phpstan-param LockMode::* $lockMode
     *
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    private function checkLockRequirements(LockMode|int $lockMode, ClassMetadata $class): void
    {
        switch ($lockMode) {
            case LockMode::OPTIMISTIC:
                if (! $class->isVersioned) {
                    throw OptimisticLockException::notVersioned($class->name);
                }

                break;
            case LockMode::PESSIMISTIC_READ:
            case LockMode::PESSIMISTIC_WRITE:
                if (! $this->getConnection()->isTransactionActive()) {
                    throw TransactionRequiredException::transactionRequired();
                }
        }
    }

    private function configureMetadataCache(): void
    {
        $metadataCache = $this->config->getMetadataCache();
        if (! $metadataCache) {
            return;
        }

        $this->metadataFactory->setCache($metadataCache);
    }

    private function createCustomHydrator(string $hydrationMode): AbstractHydrator
    {
        $class = $this->config->getCustomHydrationMode($hydrationMode);

        if ($class !== null) {
            return new $class($this);
        }

        throw InvalidHydrationMode::fromMode($hydrationMode);
    }
}
