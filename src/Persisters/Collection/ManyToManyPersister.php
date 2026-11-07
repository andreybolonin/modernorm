<?php

declare(strict_types=1);

namespace ModernORM\Persisters\Collection;

use BadMethodCallException;
use Doctrine\DBAL\LockMode;
use ModernORM\Mapping\AssociationMapping;
use ModernORM\Mapping\InverseSideMapping;
use ModernORM\Mapping\ManyToManyAssociationMapping;
use ModernORM\PersistentCollection;
use ModernORM\Utility\PersisterHelper;

use function array_fill;
use function assert;
use function count;
use function implode;
use function in_array;
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

    public function get(PersistentCollection $collection, mixed $index): object|null
    {
        $mapping = $this->getMapping($collection);

        if (! $mapping->isIndexed()) {
            throw new BadMethodCallException('Selecting a collection by index is only supported on indexed collections.');
        }

        $persister = $this->uow->getEntityPersister($mapping->targetEntity);
        $sourceClass = $this->em->getClassMetadata($mapping->sourceEntity);
        $owning = $this->em->getMetadataFactory()->getOwningSide($mapping);
        $owningClass = $this->em->getClassMetadata($owning->sourceEntity);
        $joinTable = $this->quoteStrategy->getJoinTableName($owning, $owningClass, $this->platform);
        $joinColumns = $mapping->isOwningSide() ? $owning->joinTable->joinColumns : $owning->joinTable->inverseJoinColumns;
        $identifier = $this->uow->getEntityIdentifier($collection->getOwner());
        $criteria = [$mapping->indexBy() => $index];
        foreach ($joinColumns as $column) {
            $field = $sourceClass->getFieldForColumn($column->referencedColumnName);
            $criteria[$joinTable . '.' . $this->quoteStrategy->getJoinColumnName($column, $sourceClass, $this->platform)] = $identifier[$field];
        }

        return $persister->load(
            $criteria,
            null,
            $mapping,
            [],
            LockMode::NONE,
            1,
        );
    }

    /** @return non-negative-int */
    public function count(PersistentCollection $collection): int
    {
        $conditions  = [];
        $params      = [];
        $types       = [];
        $mapping     = $this->getMapping($collection);
        $id          = $this->uow->getEntityIdentifier($collection->getOwner());
        $sourceClass = $this->em->getClassMetadata($mapping->sourceEntity);
        $association = $this->em->getMetadataFactory()->getOwningSide($mapping);

        $joinTableName = $this->quoteStrategy->getJoinTableName($association, $sourceClass, $this->platform);
        $joinColumns   = ! $mapping->isOwningSide()
            ? $association->joinTable->inverseJoinColumns
            : $association->joinTable->joinColumns;

        foreach ($joinColumns as $joinColumn) {
            $columnName     = $this->quoteStrategy->getJoinColumnName($joinColumn, $sourceClass, $this->platform);
            $referencedName = $joinColumn->referencedColumnName;
            $conditions[]   = 't.' . $columnName . ' = ?';
            $params[]       = $id[$sourceClass->getFieldForColumn($referencedName)];
            $types[]        = PersisterHelper::getTypeOfColumn($referencedName, $sourceClass, $this->em);
        }

        $sql = 'SELECT COUNT(*)'
            . ' FROM ' . $joinTableName . ' t'
            . ' WHERE ' . implode(' AND ', $conditions);

        return (int) $this->conn->fetchOne($sql, $params, $types);
    }

    /**
     * {@inheritDoc}
     */
    public function slice(PersistentCollection $collection, int $offset, int|null $length = null): array
    {
        $mapping   = $this->getMapping($collection);
        $persister = $this->uow->getEntityPersister($mapping->targetEntity);

        return $persister->getManyToManyCollection($mapping, $collection->getOwner(), $offset, $length);
    }

    public function containsKey(PersistentCollection $collection, mixed $key): bool
    {
        $mapping = $this->getMapping($collection);

        if (! $mapping->isIndexed()) {
            throw new BadMethodCallException('Selecting a collection by index is only supported on indexed collections.');
        }

        [$quotedJoinTable, $whereClauses, $params, $types] = $this->getJoinTableRestrictionsWithKey(
            $collection,
            (string) $key,
        );

        $sql = 'SELECT 1 FROM ' . $quotedJoinTable . ' WHERE ' . implode(' AND ', $whereClauses);

        return (bool) $this->conn->fetchOne($sql, $params, $types);
    }

    public function contains(PersistentCollection $collection, object $element): bool
    {
        if (! $this->isValidEntityState($element)) {
            return false;
        }

        [$quotedJoinTable, $whereClauses, $params, $types] = $this->getJoinTableRestrictions(
            $collection,
            $element,
            true,
        );

        $sql = 'SELECT 1 FROM ' . $quotedJoinTable . ' WHERE ' . implode(' AND ', $whereClauses);

        return (bool) $this->conn->fetchOne($sql, $params, $types);
    }

    /**
     * Generate ON condition
     *
     * @return string[]
     * @phpstan-return list<string>
     */
    protected function getOnConditionSQL(AssociationMapping $mapping): array
    {
        $association = $this->em->getMetadataFactory()->getOwningSide($mapping);
        $joinColumns = $mapping->isOwningSide()
            ? $association->joinTable->inverseJoinColumns
            : $association->joinTable->joinColumns;

        $conditions = [];

        $targetClass = $this->em->getClassMetadata($mapping->targetEntity);
        foreach ($joinColumns as $joinColumn) {
            $joinColumnName = $this->quoteStrategy->getJoinColumnName($joinColumn, $targetClass, $this->platform);
            $refColumnName  = $this->quoteStrategy->getReferencedJoinColumnName($joinColumn, $targetClass, $this->platform);

            $conditions[] = ' t.' . $joinColumnName . ' = te.' . $refColumnName;
        }

        return $conditions;
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

    /**
     * @param bool $useAlias Whether to alias the join table for a SELECT.
     *
     * @return mixed[] ordered vector:
     *                - quoted join table name
     *                - where clauses to be added for filtering
     *                - parameters to be bound for filtering
     *                - types of the parameters to be bound for filtering
     * @phpstan-return array{0: string, 1: list<string>, 2: list<mixed>, 3: list<string>}
     */
    private function getJoinTableRestrictionsWithKey(
        PersistentCollection $collection,
        string $key,
    ): array {
        $mapping = $this->getMapping($collection);
        $indexBy       = $mapping->indexBy();
        $id            = $this->uow->getEntityIdentifier($collection->getOwner());
        $sourceClass   = $this->em->getClassMetadata($mapping->sourceEntity);
        $targetClass   = $this->em->getClassMetadata($mapping->targetEntity);

        if (! $mapping->isOwningSide()) {
            assert($mapping instanceof InverseSideMapping);
            $associationSourceClass = $this->em->getClassMetadata($mapping->targetEntity);
            $mapping                = $associationSourceClass->associationMappings[$mapping->mappedBy];
            assert($mapping->isManyToManyOwningSide());
            $joinColumns        = $mapping->joinTable->joinColumns;
            $sourceRelationMode = 'relationToTargetKeyColumns';
            $targetRelationMode = 'relationToSourceKeyColumns';
        } else {
            assert($mapping->isManyToManyOwningSide());
            $associationSourceClass = $this->em->getClassMetadata($mapping->sourceEntity);
            $joinColumns            = $mapping->joinTable->inverseJoinColumns;
            $sourceRelationMode     = 'relationToSourceKeyColumns';
            $targetRelationMode     = 'relationToTargetKeyColumns';
        }

        $quotedJoinTable = $this->quoteStrategy->getJoinTableName($mapping, $associationSourceClass, $this->platform) . ' t';
        $whereClauses    = [];
        $params          = [];
        $types           = [];

        $joinNeeded = ! in_array($indexBy, $targetClass->identifier, true);

        if ($joinNeeded) { // extra join needed if indexBy is not a @id
            $joinConditions = [];

            foreach ($joinColumns as $joinTableColumn) {
                $joinConditions[] = 't.' . $joinTableColumn->name . ' = tr.' . $joinTableColumn->referencedColumnName;
            }

            $tableName        = $this->quoteStrategy->getTableName($targetClass, $this->platform);
            $quotedJoinTable .= ' JOIN ' . $tableName . ' tr ON ' . implode(' AND ', $joinConditions);
            $columnName       = $targetClass->getColumnName($indexBy);

            $whereClauses[] = 'tr.' . $columnName . ' = ?';
            $params[]       = $key;
            $types[]        = PersisterHelper::getTypeOfColumn($columnName, $targetClass, $this->em);
        }

        foreach ($mapping->joinTableColumns as $joinTableColumn) {
            if (isset($mapping->{$sourceRelationMode}[$joinTableColumn])) {
                $column         = $mapping->{$sourceRelationMode}[$joinTableColumn];
                $whereClauses[] = 't.' . $joinTableColumn . ' = ?';
                $params[]       = $id[$sourceClass->fieldNames[$column]];
                $types[]        = PersisterHelper::getTypeOfColumn($column, $sourceClass, $this->em);
            } elseif (! $joinNeeded) {
                $column = $mapping->{$targetRelationMode}[$joinTableColumn];

                $whereClauses[] = 't.' . $joinTableColumn . ' = ?';
                $params[]       = $key;
                $types[]        = PersisterHelper::getTypeOfColumn($column, $targetClass, $this->em);
            }
        }

        return [$quotedJoinTable, $whereClauses, $params, $types];
    }

    /**
     * @param bool $useAlias Whether to alias the join table for a SELECT.
     *
     * @return mixed[] ordered vector:
     *                - quoted join table name
     *                - where clauses to be added for filtering
     *                - parameters to be bound for filtering
     *                - types of the parameters to be bound for filtering
     * @phpstan-return array{0: string, 1: list<string>, 2: list<mixed>, 3: list<string>}
     */
    private function getJoinTableRestrictions(
        PersistentCollection $collection,
        object $element,
        bool $useAlias,
    ): array {
        $mapping = $this->getMapping($collection);

        if (! $mapping->isOwningSide()) {
            $sourceClass = $this->em->getClassMetadata($mapping->targetEntity);
            $targetClass = $this->em->getClassMetadata($mapping->sourceEntity);
            $sourceId    = $this->uow->getEntityIdentifier($element);
            $targetId    = $this->uow->getEntityIdentifier($collection->getOwner());
        } else {
            $sourceClass = $this->em->getClassMetadata($mapping->sourceEntity);
            $targetClass = $this->em->getClassMetadata($mapping->targetEntity);
            $sourceId    = $this->uow->getEntityIdentifier($collection->getOwner());
            $targetId    = $this->uow->getEntityIdentifier($element);
        }

        $mapping = $this->em->getMetadataFactory()->getOwningSide($mapping);

        $quotedJoinTable = $this->quoteStrategy->getJoinTableName($mapping, $sourceClass, $this->platform) . ($useAlias ? ' t' : '');
        $whereClauses    = [];
        $params          = [];
        $types           = [];

        foreach ($mapping->joinTableColumns as $joinTableColumn) {
            $whereClauses[] = ($useAlias ? 't.' : '') . $joinTableColumn . ' = ?';

            if (isset($mapping->relationToTargetKeyColumns[$joinTableColumn])) {
                $targetColumn = $mapping->relationToTargetKeyColumns[$joinTableColumn];
                $params[]     = $targetId[$targetClass->getFieldForColumn($targetColumn)];
                $types[]      = PersisterHelper::getTypeOfColumn($targetColumn, $targetClass, $this->em);

                continue;
            }

            // relationToSourceKeyColumns
            $targetColumn = $mapping->relationToSourceKeyColumns[$joinTableColumn];
            $params[]     = $sourceId[$sourceClass->getFieldForColumn($targetColumn)];
            $types[]      = PersisterHelper::getTypeOfColumn($targetColumn, $sourceClass, $this->em);
        }

        return [$quotedJoinTable, $whereClauses, $params, $types];
    }

    private function getMapping(PersistentCollection $collection): AssociationMapping&ManyToManyAssociationMapping
    {
        $mapping = $collection->getMapping();

        assert($mapping instanceof ManyToManyAssociationMapping);

        return $mapping;
    }
}
