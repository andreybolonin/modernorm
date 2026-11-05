<?php

declare(strict_types=1);

namespace ModernORM\Bulk;

use BackedEnum;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use ModernORM\EntityManagerInterface;
use ModernORM\Exception\BulkOperationException;
use ModernORM\Mapping\ClassMetadata;
use Throwable;

/** Single-statement PostgreSQL writes. Values use mapped field names; predicates are trusted SQL. */
final class BulkExecutor
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * @param list<array<string, mixed>> $rows All rows must have the same fields (in any order).
     * @param list<string> $returning Mapped fields; returned values are raw DBAL values.
     * @param list<class-string> $affectedEntities Additional tables written by triggers/cascades.
     */
    public function insert(string $entity, array $rows, BulkPolicy $policy, array $returning = [], array $affectedEntities = []): BulkResult
    {
        return $this->insertRows($entity, $rows, $policy, $returning, $affectedEntities, null, []);
    }

    /** Empty updateFields means ON CONFLICT DO NOTHING. Conflict fields require a matching DB constraint. */
    public function upsert(string $entity, array $rows, array $conflictFields, array $updateFields, BulkPolicy $policy, array $returning = [], array $affectedEntities = []): BulkResult
    {
        if ($conflictFields === []) {
            throw new \InvalidArgumentException('UPSERT requires explicit conflict fields.');
        }
        return $this->insertRows($entity, $rows, $policy, $returning, $affectedEntities, $conflictFields, $updateFields);
    }

    /** Predicate parameters must be named, without a leading colon; explicit TRUE allows all rows. */
    public function update(string $entity, array $values, string $where, BulkPolicy $policy, array $parameters = [], array $types = [], array $returning = [], array $affectedEntities = []): BulkResult
    {
        $metadata = $this->metadata($entity);
        $this->predicate($where, $parameters, $types);
        if ($values === []) {
            throw new \InvalidArgumentException('Bulk UPDATE requires values.');
        }
        $sets = [];
        foreach ($values as $field => $value) {
            $name = '__morm_bulk_' . count($sets);
            $sets[] = $this->column($metadata, $field) . ' = ' . $this->placeholder($metadata, $field, ':' . $name);
            $parameters[$name] = $value instanceof BackedEnum ? $value->value : $value;
            $types[$name] = $metadata->getTypeOfField($field);
        }
        return $this->execute($metadata, 'UPDATE ' . $this->table($metadata) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where . $this->returning($metadata, $returning), $parameters, $types, $policy, $affectedEntities, $returning !== []);
    }

    public function delete(string $entity, string $where, BulkPolicy $policy, array $parameters = [], array $types = [], array $returning = [], array $affectedEntities = []): BulkResult
    {
        $metadata = $this->metadata($entity);
        $this->predicate($where, $parameters, $types);
        return $this->execute($metadata, 'DELETE FROM ' . $this->table($metadata) . ' WHERE ' . $where . $this->returning($metadata, $returning), $parameters, $types, $policy, $affectedEntities, $returning !== []);
    }

    private function insertRows(string $entity, array $rows, BulkPolicy $policy, array $returning, array $affectedEntities, ?array $conflictFields, array $updateFields): BulkResult
    {
        $metadata = $this->metadata($entity);
        if ($rows === [] || !array_is_list($rows) || !is_array($rows[0]) || $rows[0] === []) {
            throw new \InvalidArgumentException('Bulk INSERT requires a nonempty list of nonempty rows.');
        }
        $fields = array_keys($rows[0]);
        $columns = array_map(fn (string $field): string => $this->column($metadata, $field), $fields);
        $parameters = $types = $tuples = [];
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) !== count($fields) || array_diff($fields, array_keys($row)) !== []) {
                throw new \InvalidArgumentException('All bulk rows must contain the same fields.');
            }
            $tuple = [];
            foreach ($fields as $field) {
                $tuple[] = $this->placeholder($metadata, $field, '?');
                $value = $row[$field];
                $parameters[] = $value instanceof BackedEnum ? $value->value : $value;
                $types[] = $metadata->getTypeOfField($field);
            }
            $tuples[] = '(' . implode(', ', $tuple) . ')';
        }
        // One atomic statement; no hidden chunking/partial commits.
        if (count($parameters) > 65535) {
            throw new \InvalidArgumentException('Bulk statement exceeds PostgreSQL parameter limit; split the call explicitly.');
        }
        $sql = 'INSERT INTO ' . $this->table($metadata) . ' (' . implode(', ', $columns) . ') VALUES ' . implode(', ', $tuples);
        if ($conflictFields !== null) {
            $conflict = array_map(fn (string $field): string => $this->column($metadata, $field), $conflictFields);
            $updates = [];
            foreach ($updateFields as $field) {
                if (!in_array($field, $fields, true)) {
                    throw new \InvalidArgumentException('UPSERT update fields must be present in every input row.');
                }
                $column = $this->column($metadata, $field);
                $updates[] = $column . ' = EXCLUDED.' . $column;
            }
            $sql .= ' ON CONFLICT (' . implode(', ', $conflict) . ') DO ' . ($updates === [] ? 'NOTHING' : 'UPDATE SET ' . implode(', ', $updates));
        }
        return $this->execute($metadata, $sql . $this->returning($metadata, $returning), $parameters, $types, $policy, $affectedEntities, $returning !== []);
    }

    private function metadata(string $entity): ClassMetadata
    {
        if (!$this->em->isOpen()) {
            throw new \LogicException('Bulk operations require an open EntityManager.');
        }
        if (!$this->em->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            throw new \LogicException('Bulk operations require PostgreSQL.');
        }
        $metadata = $this->em->getClassMetadata($entity);
        if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
            throw new \InvalidArgumentException('Bulk operations require a concrete entity table.');
        }
        return $metadata;
    }

    private function table(ClassMetadata $metadata): string
    {
        return $this->em->getConfiguration()->getQuoteStrategy()->getTableName($metadata, $this->em->getConnection()->getDatabasePlatform());
    }

    private function column(ClassMetadata $metadata, string $field): string
    {
        if (!isset($metadata->fieldMappings[$field])) {
            throw new \InvalidArgumentException('Bulk fields must be mapped scalar fields: ' . $field);
        }
        return $this->em->getConfiguration()->getQuoteStrategy()->getColumnName($field, $metadata, $this->em->getConnection()->getDatabasePlatform());
    }

    private function placeholder(ClassMetadata $metadata, string $field, string $placeholder): string
    {
        return Type::getType($metadata->getTypeOfField($field))->convertToDatabaseValueSQL($placeholder, $this->em->getConnection()->getDatabasePlatform());
    }

    private function returning(ClassMetadata $metadata, array $fields): string
    {
        $columns = [];
        foreach ($fields as $field) {
            $columns[] = $this->column($metadata, $field) . ' AS ' . $this->em->getConnection()->quoteIdentifier($field);
        }
        return $columns === [] ? '' : ' RETURNING ' . implode(', ', $columns);
    }

    private function predicate(string $where, array $parameters, array $types): void
    {
        if (trim($where) === '' || str_contains($where, '__morm_bulk_')) {
            throw new \InvalidArgumentException('Provide an explicit SQL predicate (TRUE for all rows); __morm_bulk_ is reserved.');
        }
        foreach (array_keys($parameters + $types) as $name) {
            if (!is_string($name) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $name) || str_starts_with($name, '__morm_bulk_')) {
                throw new \InvalidArgumentException('Bulk predicate parameters must have plain names without colons.');
            }
        }
    }

    /** Resolve the entire declared write set and reject unsupported cache scopes before DML. */
    private function regions(ClassMetadata $metadata, array $affectedEntities): array
    {
        $regions = [];
        foreach (array_unique([$metadata->name, ...$affectedEntities]) as $entity) {
            $class = $this->metadata($entity);
            if ($this->em->getCache() === null || $class->cache === null) {
                continue;
            }
            $this->em->getCache()->assertSupported($class);
            $regions[$class->name] = $class->name;
        }
        return $regions;
    }

    private function evict(array $regions): void
    {
        foreach ($regions as $entity) {
            $this->em->getCache()->evictAll($entity);
        }
    }

    private function execute(ClassMetadata $metadata, string $sql, array $parameters, array $types, BulkPolicy $policy, array $affectedEntities, bool $hasReturning): BulkResult
    {
        $regions = $this->regions($metadata, $affectedEntities);
        $connection = $this->em->getConnection();
        $owned = !$connection->isTransactionActive() && $connection->isAutoCommit();
        if ($regions !== [] && !$owned) {
            throw new \LogicException('Bulk cached writes require a top-level API-owned transaction.');
        }
        if ($owned) {
            $connection->beginTransaction();
        } elseif (!$connection->isTransactionActive()) {
            // DBAL may have disabled auto-commit on an already-open connection
            // without starting a transaction. Reconnecting can itself start one.
            $connection->getNativeConnection();
            if (!$connection->isTransactionActive()) {
                $connection->beginTransaction();
            }
        }
        try {
            $this->evict($regions);
            $result = $connection->executeQuery($sql, $parameters, $types);
            try {
                $rows = $hasReturning ? $result->fetchAllAssociative() : [];
                $count = $hasReturning ? count($rows) : (int) $result->rowCount();
            } finally {
                $result->free();
            }
            if ($owned && $connection->getTransactionNestingLevel() !== 1) {
                throw new \LogicException('Bulk callbacks must not change transaction nesting.');
            }
        } catch (Throwable $error) {
            if ($owned) {
                try {
                    while ($connection->isTransactionActive()) {
                        $level = $connection->getTransactionNestingLevel();
                        $connection->rollBack();
                        if ($level === 1) {
                            break; // Disabled auto-commit can immediately start another transaction.
                        }
                    }
                } catch (Throwable $rollbackError) {
                    $this->closeAfterFailure();
                    throw new BulkOperationException(null, $rollbackError);
                }
            }
            throw $error;
        }
        if ($owned) {
            try {
                $connection->commit();
                if ($connection->isTransactionActive()) {
                    throw new \LogicException('Bulk commit did not complete the owned transaction.');
                }
            } catch (Throwable $error) {
                // A transport/commit failure can be ambiguous. Never claim that a retry is safe.
                $connection->close();
                $this->closeAfterFailure();
                throw new BulkOperationException(null, $error);
            }
        }
        try {
            $this->evict($regions);
            if ($policy === BulkPolicy::CLEAR) {
                $this->em->clear();
            }
        } catch (Throwable $error) {
            $this->closeAfterFailure();
            throw new BulkOperationException($owned ? true : null, $error);
        }
        return new BulkResult($count, $rows, $owned);
    }

    private function closeAfterFailure(): void
    {
        try {
            $this->em->close();
        } catch (Throwable) {
            // Preserve the database outcome error even when onClear listeners also fail.
        }
    }
}
