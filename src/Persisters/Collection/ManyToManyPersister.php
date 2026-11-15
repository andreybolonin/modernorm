<?php

declare(strict_types=1);

namespace ModernORM\Persisters\Collection;

use ModernORM\Mapping\AssociationMapping;
use ModernORM\Mapping\ManyToManyAssociationMapping;
use ModernORM\PersistentCollection;
use ModernORM\Utility\PersisterHelper;

use function array_fill;
use function assert;
use function count;
use function implode;
use function reset;

/**
 * Persister for many-to-many collections.
 */
class ManyToManyPersister extends AbstractCollectionPersister
{
    public function delete(PersistentCollection $collection): void
    {
        $mapping = $this->getMapping($collection);

        if (! $mapping->isOwningSide()) {
            return; // ignore inverse side
        }

        assert($mapping->isManyToManyOwningSide());

        $types = [];
        $class = $this->em->getClassMetadata($mapping->sourceEntity);

        foreach ($mapping->joinTable->joinColumns as $joinColumn) {
            $types[] = PersisterHelper::getTypeOfColumn($joinColumn->referencedColumnName, $class, $this->em);
        }

        $this->conn->executeStatement($this->getDeleteSQL($collection), $this->getDeleteSQLParameters($collection), $types);
    }

    public function update(PersistentCollection $collection): void
    {
        $mapping = $this->getMapping($collection);

        if (! $mapping->isOwningSide()) {
            return; // ignore inverse side
        }

        [$deleteSql, $deleteTypes] = $this->getDeleteRowSQL($collection);
        [$insertSql, $insertTypes] = $this->getInsertRowSQL($collection);

        foreach ($collection->getDeleteDiff() as $element) {
            $this->conn->executeStatement(
                $deleteSql,
                $this->getDeleteRowSQLParameters($collection, $element),
                $deleteTypes,
            );
        }

        foreach ($collection->getInsertDiff() as $element) {
            $this->conn->executeStatement(
                $insertSql,
                $this->getInsertRowSQLParameters($collection, $element),
                $insertTypes,
            );
        }
    }

    protected function getDeleteSQL(PersistentCollection $collection): string
    {
        $columns = [];
        $mapping = $this->getMapping($collection);
        assert($mapping->isManyToManyOwningSide());
        $class     = $this->em->getClassMetadata($collection->getOwner()::class);
        $joinTable = $this->quoteStrategy->getJoinTableName($mapping, $class, $this->platform);

        foreach ($mapping->joinTable->joinColumns as $joinColumn) {
            $columns[] = $this->quoteStrategy->getJoinColumnName($joinColumn, $class, $this->platform);
        }

        return 'DELETE FROM ' . $joinTable
            . ' WHERE ' . implode(' = ? AND ', $columns) . ' = ?';
    }

    /**
     * Internal note: Order of the parameters must be the same as the order of the columns in getDeleteSql.
     *
     * @return list<mixed>
     */
    protected function getDeleteSQLParameters(PersistentCollection $collection): array
    {
        $mapping = $this->getMapping($collection);
        assert($mapping->isManyToManyOwningSide());
        $identifier = $this->uow->getEntityIdentifier($collection->getOwner());

        return array_fill(0, count($mapping->relationToSourceKeyColumns), reset($identifier));
    }

    /**
     * Gets the SQL statement used for deleting a row from the collection.
     *
     * @return string[]|string[][] ordered tuple containing the SQL to be executed and an array
     *                             of types for bound parameters
     * @phpstan-return array{0: string, 1: list<string>}
     */
    protected function getDeleteRowSQL(PersistentCollection $collection): array
    {
        $mapping = $this->getMapping($collection);
        assert($mapping->isManyToManyOwningSide());
        $class       = $this->em->getClassMetadata($mapping->sourceEntity);
        $targetClass = $this->em->getClassMetadata($mapping->targetEntity);
        $columns     = [];
        $types       = [];

        foreach ($mapping->joinTable->joinColumns as $joinColumn) {
            $columns[] = $this->quoteStrategy->getJoinColumnName($joinColumn, $class, $this->platform);
            $types[]   = PersisterHelper::getTypeOfColumn($joinColumn->referencedColumnName, $class, $this->em);
        }

        foreach ($mapping->joinTable->inverseJoinColumns as $joinColumn) {
            $columns[] = $this->quoteStrategy->getJoinColumnName($joinColumn, $targetClass, $this->platform);
            $types[]   = PersisterHelper::getTypeOfColumn($joinColumn->referencedColumnName, $targetClass, $this->em);
        }

        return [
            'DELETE FROM ' . $this->quoteStrategy->getJoinTableName($mapping, $class, $this->platform)
            . ' WHERE ' . implode(' = ? AND ', $columns) . ' = ?',
            $types,
        ];
    }

    /**
     * Gets the SQL parameters for the corresponding SQL statement to delete the given
     * element from the given collection.
     *
     * Internal note: Order of the parameters must be the same as the order of the columns in getDeleteRowSql.
     *
     * @return mixed[]
     * @phpstan-return list<mixed>
     */
    protected function getDeleteRowSQLParameters(PersistentCollection $collection, object $element): array
    {
        return $this->collectJoinTableColumnParameters($collection, $element);
    }

    /**
     * Gets the SQL statement used for inserting a row in the collection.
     *
     * @return string[]|string[][] ordered tuple containing the SQL to be executed and an array
     *                             of types for bound parameters
     * @phpstan-return array{0: string, 1: list<string>}
     */
    protected function getInsertRowSQL(PersistentCollection $collection): array
    {
        $columns = [];
        $types   = [];
        $mapping = $this->getMapping($collection);
        assert($mapping->isManyToManyOwningSide());
        $class       = $this->em->getClassMetadata($mapping->sourceEntity);
        $targetClass = $this->em->getClassMetadata($mapping->targetEntity);

        foreach ($mapping->joinTable->joinColumns as $joinColumn) {
            $columns[] = $this->quoteStrategy->getJoinColumnName($joinColumn, $targetClass, $this->platform);
            $types[]   = PersisterHelper::getTypeOfColumn($joinColumn->referencedColumnName, $class, $this->em);
        }

        foreach ($mapping->joinTable->inverseJoinColumns as $joinColumn) {
            $columns[] = $this->quoteStrategy->getJoinColumnName($joinColumn, $targetClass, $this->platform);
            $types[]   = PersisterHelper::getTypeOfColumn($joinColumn->referencedColumnName, $targetClass, $this->em);
        }

        return [
            'INSERT INTO ' . $this->quoteStrategy->getJoinTableName($mapping, $class, $this->platform)
            . ' (' . implode(', ', $columns) . ')'
            . ' VALUES'
            . ' (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
            $types,
        ];
    }

    /**
     * Gets the SQL parameters for the corresponding SQL statement to insert the given
     * element of the given collection into the database.
     *
     * Internal note: Order of the parameters must be the same as the order of the columns in getInsertRowSql.
     *
     * @return mixed[]
     * @phpstan-return list<mixed>
     */
    protected function getInsertRowSQLParameters(PersistentCollection $collection, object $element): array
    {
        return $this->collectJoinTableColumnParameters($collection, $element);
    }

    /**
     * Collects the parameters for inserting/deleting on the join table in the order
     * of the join table columns as specified in ManyToManyMapping#joinTableColumns.
     *
     * @return mixed[]
     * @phpstan-return list<mixed>
     */
    private function collectJoinTableColumnParameters(
        PersistentCollection $collection,
        object $element,
    ): array {
        $params  = [];
        $mapping = $this->getMapping($collection);
        assert($mapping->isManyToManyOwningSide());
        $sourceIdentifier = $this->uow->getEntityIdentifier($collection->getOwner());
        $targetIdentifier = $this->uow->getEntityIdentifier($element);
        $sourceId = reset($sourceIdentifier);
        $targetId = reset($targetIdentifier);

        foreach ($mapping->joinTableColumns as $joinTableColumn) {
            $params[] = isset($mapping->relationToSourceKeyColumns[$joinTableColumn]) ? $sourceId : $targetId;
        }

        return $params;
    }

    private function getMapping(PersistentCollection $collection): AssociationMapping&ManyToManyAssociationMapping
    {
        $mapping = $collection->getMapping();

        assert($mapping instanceof ManyToManyAssociationMapping);

        return $mapping;
    }
}
