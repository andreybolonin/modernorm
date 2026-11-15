<?php

declare(strict_types=1);

namespace ModernORM\Tools\Pagination;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder as SqlQueryBuilder;
use ModernORM\EntityManagerInterface;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Query\ResultSetMapping;
use ModernORM\Tools\Pagination\Exception\InvalidCursor;
use SplObjectStorage;

/** @internal Frozen SQL/RSM source. Cursor values are snapshots of raw database keys. */
final class CursorQuery
{
    /** @param array<string, mixed> $parameters
     *  @param array<string, mixed> $types
     *  @param non-empty-array<string, array{column: string, direction: string}> $keys
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClassMetadata $metadata,
        private readonly string $sql,
        private readonly array $parameters,
        private readonly array $types,
        private readonly ResultSetMapping $mapping,
        private readonly array $keys,
    ) {
    }

    public function keyQuery(?Cursor $cursor = null): SqlQueryBuilder
    {
        $query = $this->em->getConnection()->createQueryBuilder()->distinct()->from('(' . $this->sql . ')', 'morm_page')
            ->setParameters($this->parameters, $this->types);
        $expressions = [];
        $directions = [];
        foreach ($this->keys as $key) {
            $column = 'morm_page.' . $key['column'];
            $expressions[] = $column;
            $direction = $key['direction'];
            if ($cursor?->isPrevious()) {
                $direction = $direction === 'ASC' ? 'DESC' : 'ASC';
            }
            $directions[] = $direction;
            $query->addSelect($column)->addOrderBy($column, $direction);
        }
        // Outer joins may contain unmatched rows that do not represent a root entity.
        $query->where($expressions[count($expressions) - 1] . ' IS NOT NULL');
        if ($cursor !== null) {
            $values = $cursor->getParameters();
            if (count($values) !== count($this->keys) || array_diff_key($values, $this->keys) !== []) {
                throw new InvalidCursor('Cursor fields do not match the configured sorting fields.');
            }
            $parameters = [];
            foreach ($this->keys as $field => $key) {
                $parameters[] = $this->bind($query, self::databaseScalar($values[$field]));
            }
            if (count(array_unique($directions)) === 1) {
                // PostgreSQL can seek directly on a matching compound B-tree index.
                $query->andWhere('(' . implode(', ', $expressions) . ') '
                    . ($directions[0] === 'ASC' ? '>' : '<') . ' (' . implode(', ', $parameters) . ')');
            } else {
                $terms = [];
                foreach ($expressions as $index => $expression) {
                    $parts = [];
                    for ($prefix = 0; $prefix < $index; ++$prefix) {
                        $parts[] = $expressions[$prefix] . ' = ' . $parameters[$prefix];
                    }
                    $parts[] = $expression . ($directions[$index] === 'ASC' ? ' > ' : ' < ') . $parameters[$index];
                    $terms[] = '(' . implode(' AND ', $parts) . ')';
                }
                // A leading inclusive bound gives PostgreSQL a range for the OR chain.
                $query->andWhere($expressions[0] . ($directions[0] === 'ASC' ? ' >= ' : ' <= ') . $parameters[0])
                    ->andWhere('(' . implode(' OR ', $terms) . ')');
            }
        }
        return $query;
    }

    /** @param list<array<string, mixed>> $rows
     *  @return array{list<object>, SplObjectStorage<object, array<string, scalar>>}
     */
    public function hydrate(array $rows): array
    {
        $snapshots = new SplObjectStorage();
        if ($rows === []) {
            return [[], $snapshots];
        }
        $idField = $this->metadata->getSingleIdentifierFieldName();
        $valuesById = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($this->keys as $field => $key) {
                $values[$field] = self::databaseScalar($row[$key['column']] ?? null);
            }
            $valuesById[(string) $values[$idField]] = $values;
        }
        $connection = $this->em->getConnection();
        $idColumn = 'morm_page.' . $this->keys[$idField]['column'];
        $query = $connection->createQueryBuilder()->select('morm_page.*')->from('(' . $this->sql . ')', 'morm_page')
            ->setParameters($this->parameters, $this->types);
        $ids = array_map(static fn (array $values) => $values[$idField], array_values($valuesById));
        $binding = $this->bind($query, $ids, ArrayParameterType::STRING);
        $query->where($idColumn . ' IN (' . $binding . ')');
        $hydrate = $this->em->createQuery($query->getSQL(), clone $this->mapping);
        foreach ($query->getParameters() as $name => $value) {
            $hydrate->setParameter($name, $value, $query->getParameterType($name));
        }
        $byId = [];
        foreach ($hydrate->getResult() as $item) {
            if (! is_object($item)) {
                throw new \LogicException('Cursor pagination requires entity-only hydration.');
            }
            $id = $this->em->getUnitOfWork()->getEntityIdentifier($item)[$idField];
            if ($id instanceof \BackedEnum) {
                $id = $id->value;
            }
            $id = $connection->convertToDatabaseValue($id, $this->metadata->fieldMappings[$idField]->type);
            $key = (string) self::databaseScalar($id);
            if (! isset($valuesById[$key])) {
                throw new \LogicException('Hydration returned a root outside the requested cursor page.');
            }
            $byId[$key] = $item;
            $snapshots[$item] = $valuesById[$key];
        }
        $items = [];
        foreach ($valuesById as $id => $values) {
            if (isset($byId[$id])) {
                $items[] = $byId[$id];
            }
        }
        return [$items, $snapshots];
    }

    public function count(): int
    {
        $keys = $this->keyQuery()->resetOrderBy();
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM (' . $keys->getSQL() . ') morm_count',
            $keys->getParameters(),
            $keys->getParameterTypes(),
        );
    }

    private function bind(SqlQueryBuilder $query, mixed $value, ParameterType|ArrayParameterType $type = ParameterType::STRING): string
    {
        $number = 0;
        do {
            $name = '__orm_cursor_' . ++$number;
        } while (array_key_exists($name, $query->getParameters()));
        $query->setParameter($name, $value, $type);
        return ':' . $name;
    }

    private static function databaseScalar(mixed $value): string|int|float
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (! is_string($value) && ! is_int($value) && (! is_float($value) || ! is_finite($value))) {
            throw new InvalidCursor('Cursor sorting values must be non-null finite database scalars.');
        }
        return $value;
    }
}
