<?php

declare(strict_types=1);

namespace ModernORM\Decorator;

use DateTimeInterface;
use Doctrine\Common\EventManagerInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use ModernORM\Cache;
use ModernORM\Configuration;
use ModernORM\EntityManagerInterface;
use ModernORM\EntityRepository;
use ModernORM\Internal\Hydration\AbstractHydrator;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\ClassMetadataFactory;
use ModernORM\NativeQuery;
use ModernORM\Proxy\ProxyFactory;
use ModernORM\Query;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder as Expr;
use ModernORM\Query\ResultSetMapping;
use ModernORM\QueryBuilder;
use ModernORM\UnitOfWork;
use Doctrine\Persistence\ObjectManagerDecorator;

/**
 * Base class for EntityManager decorators
 *
 * @extends ObjectManagerDecorator<EntityManagerInterface>
 */
abstract class EntityManagerDecorator extends ObjectManagerDecorator implements EntityManagerInterface
{
    public function __construct(EntityManagerInterface $wrapped)
    {
        $this->wrapped = $wrapped;
    }

    public function getRepository(string $className): EntityRepository
    {
        return $this->wrapped->getRepository($className);
    }

    public function findIncludingDeleted(string $className, mixed $id): ?object
    {
        return $this->wrapped->findIncludingDeleted($className, $id);
    }

    public function restore(object $entity): void
    {
        $this->wrapped->restore($entity);
    }

    public function hardDelete(object $entity): void
    {
        $this->wrapped->hardDelete($entity);
    }

    public function getStatistics(): ?\ModernORM\Statistics
    {
        return $this->wrapped->getStatistics();
    }

    public function getMetadataFactory(): ClassMetadataFactory
    {
        return $this->wrapped->getMetadataFactory();
    }

    public function getClassMetadata(string $className): ClassMetadata
    {
        return $this->wrapped->getClassMetadata($className);
    }

    public function getConnection(): Connection
    {
        return $this->wrapped->getConnection();
    }

    public function bulk(): \ModernORM\Bulk\BulkExecutor
    {
        return new \ModernORM\Bulk\BulkExecutor($this);
    }

    public function getExpressionBuilder(): Expr
    {
        return $this->wrapped->getExpressionBuilder();
    }

    public function beginTransaction(): void
    {
        $this->wrapped->beginTransaction();
    }

    public function wrapInTransaction(callable $func): mixed
    {
        return $this->wrapped->wrapInTransaction($func);
    }

    public function commit(): void
    {
        $this->wrapped->commit();
    }

    public function rollback(): void
    {
        $this->wrapped->rollback();
    }

    public function createQuery(string $sql, ResultSetMapping $rsm): Query
    {
        return $this->wrapped->createQuery($sql, $rsm);
    }

    public function createNativeQuery(string $sql, ResultSetMapping $rsm): NativeQuery
    {
        return $this->wrapped->createNativeQuery($sql, $rsm);
    }

    public function createQueryBuilder(): QueryBuilder
    {
        return $this->wrapped->createQueryBuilder();
    }

    public function getReference(string $entityName, mixed $id): object|null
    {
        return $this->wrapped->getReference($entityName, $id);
    }

    public function close(): void
    {
        $this->wrapped->close();
    }

    public function lock(object $entity, LockMode|int $lockMode, DateTimeInterface|int|null $lockVersion = null): void
    {
        $this->wrapped->lock($entity, $lockMode, $lockVersion);
    }

    public function find(string $className, mixed $id, LockMode|int|null $lockMode = LockMode::NONE, int|null $lockVersion = null): object|null
    {
        return $this->wrapped->find($className, $id, $lockMode, $lockVersion);
    }

    public function refresh(object $object, LockMode|int|null $lockMode = LockMode::NONE): void
    {
        $this->wrapped->refresh($object, $lockMode);
    }

    public function getEventManager(): EventManagerInterface
    {
        return $this->wrapped->getEventManager();
    }

    public function getConfiguration(): Configuration
    {
        return $this->wrapped->getConfiguration();
    }

    public function isOpen(): bool
    {
        return $this->wrapped->isOpen();
    }

    public function getUnitOfWork(): UnitOfWork
    {
        return $this->wrapped->getUnitOfWork();
    }

    public function newHydrator(string|int $hydrationMode): AbstractHydrator
    {
        return $this->wrapped->newHydrator($hydrationMode);
    }

    public function getProxyFactory(): ProxyFactory
    {
        return $this->wrapped->getProxyFactory();
    }

    public function getCache(): Cache|null
    {
        return $this->wrapped->getCache();
    }
}
