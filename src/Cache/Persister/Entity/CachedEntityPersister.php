<?php

declare(strict_types=1);

namespace ModernORM\Cache\Persister\Entity;

use Doctrine\DBAL\LockMode;
use ModernORM\Cache\EntityCacheKey;
use ModernORM\Cache\CacheWritePolicy;
use ModernORM\Cache\ReadOnlyEntityHydrator;
use ModernORM\Cache\Region\ReadOnlyRegion;
use ModernORM\EntityManagerInterface;
use ModernORM\Exception\CacheSynchronizationException;
use ModernORM\Exception\CannotUpdateReadOnlyEntity;
use ModernORM\Mapping\AssociationMapping;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Persisters\Entity\EntityPersister;

/** @internal Stable scope: scalar entity-by-ID regions; no L2 query or association caching. */
final class CachedEntityPersister extends AbstractEntityPersister
{
    private array $invalidations = [];
    private readonly ReadOnlyEntityHydrator $hydrator;
    private readonly ?\ModernORM\Cache\Logging\CacheLogger $cacheLogger;
    public function __construct(
        EntityPersister $persister,
        private readonly ReadOnlyRegion $region,
        private readonly EntityManagerInterface $manager,
        private readonly ClassMetadata $class,
    ) {
        parent::__construct($persister);
        $this->hydrator = new ReadOnlyEntityHydrator($manager);
        $this->cacheLogger = $manager->getConfiguration()->getSecondLevelCacheConfiguration()->logger;
    }
    /** @internal */
    public function getCacheRegion(): ReadOnlyRegion
    {
        return $this->region;
    }
    public function update(object $entity): void
    {
        if ($this->class->cache['writePolicy'] !== CacheWritePolicy::INVALIDATE) {
            throw CannotUpdateReadOnlyEntity::fromEntity($entity::class);
        }
        $this->assertOwnedWriteTransaction();
        $key = new EntityCacheKey($this->class->name, $this->manager->getUnitOfWork()->getEntityIdentifier($entity));
        if ($key->hash !== $this->key($entity)->hash) {
            throw new \LogicException('Cached ORM UPDATE does not support primary key changes.');
        }
        $this->invalidate($key);
        $this->persister->update($entity);
    }
    private function key(object $entity): EntityCacheKey
    {
        return new EntityCacheKey($this->class->name, $this->class->getIdentifierValues($entity));
    }
    private function invalidate(EntityCacheKey $key): void
    {
        $this->invalidations[$key->hash] = $key;
        $this->region->evict($key);
    }
    private function assertOwnedWriteTransaction(): void
    {
        if (! $this->manager->getConnection()->isAutoCommit() || $this->manager->getConnection()->getTransactionNestingLevel() !== 1) {
            throw new \LogicException('Cached entity writes require a top-level ORM-owned flush transaction.');
        }
    }
    public function executeInserts(): void
    {
        $entities = $this->persister->getInserts();
        if ($entities === []) {
            return;
        }
        $this->assertOwnedWriteTransaction();
        foreach ($entities as $entity) {
            if ($this->class->getIdentifierValues($entity) !== []) {
                $this->invalidate($this->key($entity));
            }
        }
        try {
            $this->persister->executeInserts();
        } finally {
            foreach ($entities as $entity) {
                if ($this->class->getIdentifierValues($entity) !== []) {
                    $this->invalidate($this->key($entity));
                }
            }
        }
    }
    public function delete(object $entity): bool
    {
        $this->assertOwnedWriteTransaction();
        $this->invalidate(new EntityCacheKey($this->class->name, $this->manager->getUnitOfWork()->getEntityIdentifier($entity)));
        return $this->persister->delete($entity);
    }
    public function afterTransactionComplete(): void
    {
        try {
            if ($this->invalidations !== [] && $this->manager->getConnection()->isTransactionActive()) {
                $this->invalidations = [];
                throw new \LogicException('Cached writes did not reach a final top-level commit.');
            }
            $this->invalidatePendingWrites();
        } catch (\Throwable $error) {
            $committed = $this->manager->getConnection()->isTransactionActive() ? null : true;
            try {
                $this->manager->close();
            } catch (\Throwable) { /* Preserve the cache outcome if onClear also fails. */
            }
            throw new CacheSynchronizationException($error, $committed);
        }
    }
    public function afterTransactionRolledBack(): void
    {
        $this->invalidations = [];
    }
    public function invalidatePendingWrites(): bool
    {
        $keys = $this->invalidations;
        $this->invalidations = [];
        $failure = null;
        foreach ($keys as $key) {
            try {
                $this->region->evict($key);
            } catch (\Throwable $error) {
                $failure ??= $error;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
        return $keys !== [];
    }
    public function loadById(array $identifier, ?object $entity = null): ?object
    {
        if (! $this->manager->getConnection()->isAutoCommit() || $this->manager->getConnection()->isTransactionActive()) {
            return $this->persister->loadById($identifier, $entity);
        }
        $key = new EntityCacheKey($this->class->name, $identifier);
        $ticket = $this->region->ticket($key);
        $entry = $this->region->getAt($ticket);
        if ($entry !== null) {
            $result = $this->hydrator->loadCacheEntry($this->class, $key, $entry, $entity);
            if ($result !== null) {
                $this->cacheLogger?->hit($this->class->name);
                return $result;
            }
        }
        $this->cacheLogger?->miss($this->class->name);
        $sql = $this->persister->getSelectSQL($identifier);
        [$params, $types] = $this->persister->expandParameters($identifier);
        $row = $this->manager->getConnection()->executeQuery($sql, $params, $types)->fetchAssociative();
        if ($row === false) {
            return null;
        }
        $data = [];
        foreach ($this->persister->getResultSetMapping()->fieldMappings as $column => $field) {
            $data[$field] = $row[$column];
        }
        $entry = $this->hydrator->fromDatabaseRow($this->class, $data);
        $this->region->putAt($ticket, $entry);
        $this->cacheLogger?->put($this->class->name);
        return $this->hydrator->loadCacheEntry($this->class, $key, $entry, $entity);
    }
    /**
     * @internal Lazy ManyToOne batch: validate hits, SELECT only misses, then hydrate
     * the entire batch before PostLoad. Never publish managed state or negative entries.
     * @param list<array<string, mixed>> $identifiers
     * @return list<object>
     */
    public function loadBatchById(array $identifiers, object $trigger, ?\ModernORM\Statistics\BatchMeasurement $measurement = null): array
    {
        $connection = $this->manager->getConnection();
        $useCache = $connection->isAutoCommit() && !$connection->isTransactionActive();
        $field = $this->class->getSingleIdentifierFieldName();
        $keys = $tickets = $data = $missing = [];
        foreach ($identifiers as $identifier) {
            $key = new EntityCacheKey($this->class->name, $identifier);
            $keys[$key->hash] = $key;
            if ($useCache) {
                // Capture every miss ticket before SELECT; invalidation during the read
                // makes a late publication on this ticket unreachable.
                $tickets[$key->hash] = $this->region->ticket($key);
                $entry = $this->region->getAt($tickets[$key->hash]);
                $values = $entry === null ? null : $this->hydrator->getData($this->class, $key, $entry);
                if ($values !== null) {
                    $data[$key->hash] = $values;
                    if ($measurement !== null) {
                        ++$measurement->cacheHits;
                    }
                    $this->cacheLogger?->hit($this->class->name);
                    continue;
                }
                if ($measurement !== null) {
                    ++$measurement->cacheMisses;
                }
                $this->cacheLogger?->miss($this->class->name);
            }
            $missing[$key->hash] = $identifier[$field];
        }
        if ($useCache) {
            $this->assertCacheReadTransaction();
        }
        if ($missing !== []) {
            $criteria = [$field => array_values($missing)];
            $sql = $this->persister->getSelectSQL($criteria);
            [$params, $types] = $this->persister->expandParameters($criteria);
            $result = $connection->executeQuery($sql, $params, $types);
            try {
                $rows = $result->fetchAllAssociative();
            } finally {
                $result->free();
            }
            if ($useCache) {
                $this->assertCacheReadTransaction();
            }
            $mapping = $this->persister->getResultSetMapping();
            $idType = \Doctrine\DBAL\Types\Type::getType($this->class->getTypeOfField($field));
            foreach ($rows as $row) {
                $raw = [];
                foreach ($mapping->fieldMappings as $column => $name) {
                    $raw[$name] = $row[$column];
                }
                $id = $idType->convertToPHPValue($raw[$field], $connection->getDatabasePlatform());
                $key = new EntityCacheKey($this->class->name, [$field => $id]);
                if (!isset($missing[$key->hash])) {
                    throw new \LogicException('Cached batch SELECT returned an unexpected identifier.');
                }
                $entry = $this->hydrator->fromDatabaseRow($this->class, $raw);
                $values = $this->hydrator->getData($this->class, $keys[$key->hash], $entry);
                if ($values === null) {
                    throw new \LogicException('Invalid raw entity row in cached batch SELECT.');
                }
                $data[$key->hash] = $values;
                if ($useCache) {
                    $this->region->putAt($tickets[$key->hash], $entry);
                    if ($measurement !== null) {
                        ++$measurement->cachePuts;
                    }
                    $this->cacheLogger?->put($this->class->name);
                }
            }
        }
        // No managed state is changed until cache reads and SQL have succeeded.
        // Do not force REFRESH: a peer initialized/changed by reentrant user code
        // must retain its values and snapshots. HINT_TRIGGER handles the active ghost.
        if ($useCache) {
            $this->assertCacheReadTransaction();
        }
        $entities = [];
        $hints = [\ModernORM\Batch\LazyReferenceQueue::HINT_TRIGGER => $trigger];
        $uow = $this->manager->getUnitOfWork();
        foreach ($keys as $hash => $key) {
            if (isset($data[$hash])) {
                $entities[] = $uow->createEntity($this->class->name, $data[$hash], $hints);
            }
        }
        $uow->hydrationComplete();
        return $entities;
    }
    private function assertCacheReadTransaction(): void
    {
        if (!$this->manager->getConnection()->isAutoCommit() || $this->manager->getConnection()->isTransactionActive()) {
            throw new \LogicException('Callbacks must not change transaction mode/nesting during a cached batch read.');
        }
    }
    public function load(array $criteria, ?object $entity = null, ?AssociationMapping $assoc = null, array $hints = [], LockMode|int|null $lockMode = null, ?int $limit = null, ?array $orderBy = null): ?object
    {
        return $this->persister->load($criteria, $entity, $assoc, $hints, $lockMode, $limit, $orderBy);
    }
    public function loadAll(array $criteria = [], ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        return $this->persister->loadAll($criteria, $orderBy, $limit, $offset);
    }
    public function exists(object $entity, array $extraConditions = []): bool
    {
        return $this->persister->exists($entity, $extraConditions);
    }
    public function refresh(array $id, object $entity, LockMode|int|null $lockMode = null): void
    {
        $this->region->evict(new EntityCacheKey($this->class->name, $id));
        $this->persister->refresh($id, $entity, $lockMode);
    }
}
