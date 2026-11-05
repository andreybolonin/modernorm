<?php

declare(strict_types=1);

namespace ModernORM\Batch;

use Doctrine\DBAL\Types\Type;
use ModernORM\EntityManagerInterface;
use ModernORM\Mapping\AssociationMapping;
use ModernORM\Mapping\ManyToManyAssociationMapping;
use ModernORM\PersistentCollection;
use ModernORM\Query;
use ModernORM\UnitOfWork;
use ModernORM\Utility\PersisterHelper;

/** @internal One SQL result row per join-table membership, preserving shared entity identities. */
final class ManyToManyBatchLoader
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /** @param array<int, PersistentCollection> $collections */
    public function load(AssociationMapping&ManyToManyAssociationMapping $mapping, array $collections): array
    {
        $source = $this->em->getClassMetadata($mapping->sourceEntity);
        $owning = $this->em->getMetadataFactory()->getOwningSide($mapping);
        $owningSource = $this->em->getClassMetadata($owning->sourceEntity);
        $platform = $this->em->getConnection()->getDatabasePlatform();
        $quote = $this->em->getConfiguration()->getQuoteStrategy();
        $joinColumn = $mapping->isOwningSide() ? $owning->joinTable->joinColumns[0] : $owning->joinTable->inverseJoinColumns[0];
        $ownerColumn = $quote->getJoinTableName($owning, $owningSource, $platform)
            . '.' . $quote->getJoinColumnName($joinColumn, $owningSource, $platform);
        $field = $source->getFieldForColumn($joinColumn->referencedColumnName);
        $type = PersisterHelper::getTypeOfColumn($joinColumn->referencedColumnName, $source, $this->em);
        $ids = $byId = [];
        foreach ($collections as $oid => $collection) {
            $value = $source->getFieldValue($collection->getOwner(), $field);
            if ($value instanceof \BackedEnum) { $value = $value->value; }
            $ids[] = $value;
            $byId[UnitOfWork::getIdHashByIdentifier([$value])] = $oid;
        }
        $uow = $this->em->getUnitOfWork();
        $persister = $uow->getEntityPersister($mapping->targetEntity);
        $sql = $persister->getSelectSQL([$ownerColumn => $ids], $mapping);
        $ownerExpression = Type::getType($type)->convertToPHPValueSQL($ownerColumn, $platform);
        $sql = 'SELECT ' . $ownerExpression . ' AS orm_batch_owner, ' . substr($sql, 7);
        $resultMapping = clone $persister->getResultSetMapping();
        $resultMapping->addScalarResult('orm_batch_owner', 'orm_batch_owner', $type);
        $statement = $this->em->getConnection()->executeQuery(
            $sql,
            $ids,
            array_fill(0, count($ids), $type),
        );
        $target = $this->em->getClassMetadata($mapping->targetEntity);
        $index = $mapping->isIndexed() ? $target->getPropertyAccessor($mapping->indexBy()) : null;
        $rows = array_fill_keys(array_keys($collections), []);
        // Internal row iteration avoids root deduplication losing a target shared by several owners.
        // This does not relax the public query API's mixed-result streaming restriction.
        foreach ($this->em->newHydrator(Query::HYDRATE_OBJECT)->toIterable($statement, $resultMapping, [
            UnitOfWork::HINT_DEFEREAGERLOAD => true,
        ]) as $row) {
            $entity = $row[0];
            if ($uow->isScheduledForDelete($entity)) { continue; }
            $oid = $byId[UnitOfWork::getIdHashByIdentifier([$row['orm_batch_owner']])];
            if ($index !== null) {
                $rows[$oid][$index->getValue($entity)] = $entity;
            } else {
                $rows[$oid][] = $entity;
            }
        }
        return $rows;
    }
}
