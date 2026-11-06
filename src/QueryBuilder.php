<?php

declare(strict_types=1);

namespace ModernORM;

use Doctrine\DBAL\Query\QueryBuilder as SqlQueryBuilder;
use ModernORM\Query\EntityResultPlan;
use ModernORM\Query\ResultSetMapping;

/** SQL builder backed by DBAL, with optional explicit entity result mappings. */
class QueryBuilder extends SqlQueryBuilder
{
    private ResultSetMapping $mapping;
    /** @var array<string, EntityResultPlan> */
    private array $entities = [];
    private bool $includeDeleted = false;
    private array $joinedAliases = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct($em->getConnection());
        $this->mapping = new ResultSetMapping();
    }

    public function select(string ...$expressions): self
    {
        if ($this->entities !== []) {
            throw new \LogicException('Cannot replace a complete entity projection. Use a separate SQL builder and explicit result mapping.');
        }
        parent::select(...$expressions);
        return $this;
    }

    /** Select a complete entity projection, including MappedSuperclass fields and foreign-key columns. */
    public function fromEntity(string $class, string $alias): self
    {
        if ($this->entities !== []) {
            throw new \LogicException('fromEntity defines one root. Use explicit joins and addJoinedEntity for associations.');
        }
        $plan = EntityResultPlan::forEntity($this->em, $class, $alias);
        $this->entities[$alias] = $plan;
        $plan->apply($this->mapping, $class, $alias);
        parent::select(...$plan->select)->from($this->entityTable($class, $plan->table), $alias);
        $metadata = $this->em->getClassMetadata($class);
        $number = 0;
        foreach ($metadata->softDeleteToOneAssociations as $field) {
            $association = $metadata->associationMappings[$field];
            assert($association->isToOneOwningSide());
            $target = $this->em->getClassMetadata($association->targetEntity);
            $joined = substr($alias, 0, 28) . '_sd' . ++$number;
            $this->addJoinedEntity($target->name, $joined, $alias, $association->fieldName);
            $platform = $this->em->getConnection()->getDatabasePlatform();
            $quote = $this->em->getConfiguration()->getQuoteStrategy();
            $joinColumn = $association->joinColumns[0];
            $condition = $alias . '.' . $quote->getJoinColumnName($joinColumn, $metadata, $platform)
                . ' = ' . $joined . '.' . $quote->getReferencedJoinColumnName($joinColumn, $metadata, $platform);
            $table = $quote->getTableName($target, $platform);
            $this->leftJoin($alias, $table, $joined, $condition);
        }
        return $this;
    }

    /** Map an explicit SQL join; register SoftDelete targets before adding their join. */
    public function addJoinedEntity(string $class, string $alias, string $parent, string $relation): self
    {
        if (isset($this->entities[$alias]) || ! isset($this->entities[$parent])) {
            throw new \InvalidArgumentException('Joined result requires a unique alias and an existing parent.');
        }
        $parentClass = $this->mapping->aliasMap[$parent];
        $association = $this->em->getClassMetadata($parentClass)->getAssociationMapping($relation);
        if ($association->targetEntity !== $class) {
            throw new \InvalidArgumentException('Joined entity does not match the mapped association.');
        }
        if (isset($this->joinedAliases[$alias]) && $this->em->getClassMetadata($class)->softDeleteField !== null) {
            throw new \LogicException('Register a SoftDelete joined entity with addJoinedEntity() before adding its SQL join.');
        }
        $plan = EntityResultPlan::forEntity($this->em, $class, $alias);
        $plan->apply($this->mapping, $class, $alias, $parent, $relation);
        $this->entities[$alias] = $plan;
        $this->addSelect(...$plan->select);
        return $this;
    }

    /** Explicit visibility for this query only; call before registering entity projections. */
    public function withDeleted(): self
    {
        if ($this->entities !== []) {
            throw new \LogicException('withDeleted() must be called before fromEntity().');
        }
        $this->includeDeleted = true;
        return $this;
    }

    private function entityTable(string $class, string $table): string
    {
        $condition = $this->includeDeleted ? null : \ModernORM\Behavior\SoftDeletePolicy::activeCondition($this->em, $this->em->getClassMetadata($class));
        return $condition === null ? $table : '(SELECT * FROM ' . $table . ' WHERE ' . $condition . ')';
    }

    private function joinedTable(string $table, string $alias): string
    {
        $this->joinedAliases[$alias] = true;
        $class = $this->mapping->aliasMap[$alias] ?? null;
        return $class === null ? $table : $this->entityTable($class, $table);
    }

    public function innerJoin(string $fromAlias, string $join, string $alias, ?string $condition = null): self
    {
        parent::innerJoin($fromAlias, $this->joinedTable($join, $alias), $alias, $condition);
        return $this;
    }

    public function leftJoin(string $fromAlias, string $join, string $alias, ?string $condition = null): self
    {
        parent::leftJoin($fromAlias, $this->joinedTable($join, $alias), $alias, $condition);
        return $this;
    }

    public function rightJoin(string $fromAlias, string $join, string $alias, ?string $condition = null): self
    {
        parent::rightJoin($fromAlias, $this->joinedTable($join, $alias), $alias, $condition);
        return $this;
    }

    public function fullJoin(string $fromAlias, string $join, string $alias, ?string $condition = null): self
    {
        parent::fullJoin($fromAlias, $this->joinedTable($join, $alias), $alias, $condition);
        return $this;
    }

    public function column(string $alias, string $field): string
    {
        return $this->entities[$alias]->columns[$field]
            ?? throw new \InvalidArgumentException('Unknown mapped SQL field.');
    }

    public function addScalar(string $expression, string $column, string $resultAlias, string $type = 'string'): self
    {
        if (strlen($column) > 63 || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $column)) {
            throw new \InvalidArgumentException('Invalid SQL result alias.');
        }
        if (isset($this->mapping->columnOwnerMap[$column]) || isset($this->mapping->scalarMappings[$column])) {
            throw new \InvalidArgumentException('SQL result alias is already mapped.');
        }
        $this->addSelect($expression . ' AS ' . $column);
        $this->mapping->addScalarResult($column, $resultAlias, $type);
        return $this;
    }

    public function setResultSetMapping(ResultSetMapping $mapping): self
    {
        $this->mapping = clone $mapping;
        return $this;
    }

    public function getQuery(): Query
    {
        if ($this->mapping->aliasMap === [] && $this->mapping->scalarMappings === []) {
            throw new \LogicException('ORM hydration requires fromEntity, addScalar or an explicit ResultSetMapping. Use executeQuery for SQL rows.');
        }
        $unlimited = clone $this;
        $unlimited->setFirstResult(0)->setMaxResults(null);
        $query = $this->em->createQuery($unlimited->getSQL(), clone $this->mapping)
            ->setFirstResult($this->getFirstResult())->setMaxResults($this->getMaxResults());
        foreach ($this->getParameters() as $name => $value) {
            $type = $this->getParameterType($name);
            if ($type instanceof \Doctrine\DBAL\Types\Type) {
                $type = \Doctrine\DBAL\Types\Type::lookupName($type);
            }
            $query->setParameter($name, $value, $type);
        }
        return $query;
    }

    public function __clone()
    {
        parent::__clone();
        $this->mapping = clone $this->mapping;
    }
}
