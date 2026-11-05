<?php

declare(strict_types=1);

namespace ModernORM\Cache\Persister\Entity;

use Doctrine\DBAL\LockMode;
use ModernORM\Cache\Persister\CachedPersister;
use ModernORM\Mapping\AssociationMapping;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\PersistentCollection;
use ModernORM\Persisters\Entity\EntityPersister;
use ModernORM\Query\ResultSetMapping;

/** @internal SQL delegation shared by the entity cache wrapper. */
abstract class AbstractEntityPersister implements EntityPersister, CachedPersister
{
    public function addInsert(object $entity): void
    {
        $this->persister->addInsert($entity);
    }

    public function getInserts(): array
    {
        return $this->persister->getInserts();
    }

    public function getSelectSQL(
        array $criteria,
        AssociationMapping|null $assoc = null,
        LockMode|int|null $lockMode = null,
        int|null $limit = null,
        int|null $offset = null,
        array|null $orderBy = null,
    ): string {
        return $this->persister->getSelectSQL($criteria, $assoc, $lockMode, $limit, $offset, $orderBy);
    }

    public function getCountSQL(array $criteria = []): string
    {
        return $this->persister->getCountSQL($criteria);
    }

    public function getInsertSQL(): string
    {
        return $this->persister->getInsertSQL();
    }

    public function getResultSetMapping(): ResultSetMapping
    {
        return $this->persister->getResultSetMapping();
    }

    public function getSelectConditionStatementSQL(
        string $field,
        mixed $value,
        AssociationMapping|null $assoc = null,
    ): string {
        return $this->persister->getSelectConditionStatementSQL($field, $value, $assoc);
    }

    public function expandParameters(array $criteria): array
    {
        return $this->persister->expandParameters($criteria);
    }

    public function getClassMetadata(): ClassMetadata
    {
        return $this->persister->getClassMetadata();
    }

    public function getManyToManyCollection(
        AssociationMapping $assoc,
        object $sourceEntity,
        int|null $offset = null,
        int|null $limit = null,
    ): array {
        return $this->persister->getManyToManyCollection($assoc, $sourceEntity, $offset, $limit);
    }

    public function getOneToManyCollection(
        AssociationMapping $assoc,
        object $sourceEntity,
        int|null $offset = null,
        int|null $limit = null,
    ): array {
        return $this->persister->getOneToManyCollection($assoc, $sourceEntity, $offset, $limit);
    }

    public function getOwningTable(string $fieldName): string
    {
        return $this->persister->getOwningTable($fieldName);
    }

    public function count(array $criteria = []): int
    {
        return $this->persister->count($criteria);
    }

    public function loadOneToOneEntity(AssociationMapping $assoc, object $sourceEntity, array $identifier = []): object|null
    {
        return $this->persister->loadOneToOneEntity($assoc, $sourceEntity, $identifier);
    }

    public function lock(array $criteria, LockMode|int $lockMode): void
    {
        $this->persister->lock($criteria, $lockMode);
    }

    public function loadManyToManyCollection(AssociationMapping $assoc, object $sourceEntity, PersistentCollection $collection): array
    {
        return $this->persister->loadManyToManyCollection($assoc, $sourceEntity, $collection);
    }

    public function loadOneToManyCollection(AssociationMapping $assoc, object $sourceEntity, PersistentCollection $collection): mixed
    {
        return $this->persister->loadOneToManyCollection($assoc, $sourceEntity, $collection);
    }
}
