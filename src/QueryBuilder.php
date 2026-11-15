<?php

declare(strict_types=1);

namespace ModernORM;

use Doctrine\DBAL\Query\QueryBuilder as SqlQueryBuilder;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\UnionType;
use Doctrine\DBAL\Query\ForUpdate\ConflictResolutionMode;
use ModernORM\Tools\Pagination\CursorQuery;
use ModernORM\Mapping\FullTextIndex;
use ModernORM\Query\EntityResultPlan;
use ModernORM\Query\ResultSetMapping;
use ModernORM\Query\Expression\JsonbExpression;
use Doctrine\DBAL\Types\JsonbType;
use Doctrine\DBAL\Types\JsonbObjectType;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\JsonObjectType;

/** SQL builder backed by DBAL, with optional explicit entity result mappings. */
class QueryBuilder extends SqlQueryBuilder
{
    private ResultSetMapping $mapping;
    /** @var array<string, EntityResultPlan> */
    private array $entities = [];
    private bool $includeDeleted = false;
    private bool $deletedOnly = false;
    private array $joinedAliases = [];
    /** @var array<string, array<string, array{vector: string, query: string}>> */
    private array $fullTextSearches = [];
    private int $fullTextParameterCounter = 0;
    private int $jsonbParameterCounter = 0;
    private bool $cursorUnsupportedOperation = false;
    private bool $customResultSetMapping = false;

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
        $metadata = $this->em->getClassMetadata($class);
        if ($this->deletedOnly && $metadata->softDeleteField === null) {
            throw new \LogicException('onlyDeleted() requires a SoftDelete root entity.');
        }
        $plan = EntityResultPlan::forEntity($this->em, $class, $alias);
        $this->entities[$alias] = $plan;
        $plan->apply($this->mapping, $class, $alias);
        parent::select(...$plan->select)->from($this->entityTable($class, $plan->table, true), $alias);
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
        $this->deletedOnly = false;
        return $this;
    }

    /** Select deleted roots only; associations retain ordinary active visibility. */
    public function onlyDeleted(): self
    {
        if ($this->entities !== []) {
            throw new \LogicException('onlyDeleted() must be called before fromEntity().');
        }
        $this->includeDeleted = false;
        $this->deletedOnly = true;
        return $this;
    }

    private function entityTable(string $class, string $table, bool $root = false): string
    {
        $metadata = $this->em->getClassMetadata($class);
        $condition = $this->includeDeleted ? null : ($root && $this->deletedOnly
            ? \ModernORM\Behavior\SoftDeletePolicy::deletedCondition($this->em, $metadata)
            : \ModernORM\Behavior\SoftDeletePolicy::activeCondition($this->em, $metadata));
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

    /** Build parameterized expressions for a mapped JSONB field on a registered entity alias. */
    public function jsonb(string $alias, string $field): JsonbExpression
    {
        if (! isset($this->entities[$alias], $this->mapping->aliasMap[$alias])) {
            throw new \InvalidArgumentException('JSONB expressions require an alias registered with fromEntity() or addJoinedEntity().');
        }
        $metadata = $this->em->getClassMetadata($this->mapping->aliasMap[$alias]);
        $mapping = $metadata->fieldMappings[$field] ?? null;
        if ($mapping === null || $mapping->columnDefinition !== null) {
            throw new \InvalidArgumentException('JSONB expressions require a mapped JSONB scalar field without a custom columnDefinition.');
        }
        $type = $this->em->getConnection()->getConfiguration()->getTypeProvider()->get($mapping->type);
        $jsonb = $type instanceof JsonbType || $type instanceof JsonbObjectType
            || (($type instanceof JsonType || $type instanceof JsonObjectType) && ($mapping->options['jsonb'] ?? false) === true);
        if (! $jsonb) {
            throw new \InvalidArgumentException('JSONB expressions require a JSONB mapping; ordinary JSON and text columns are not cast implicitly.');
        }
        return new JsonbExpression($this, $this->column($alias, $field));
    }

    /** @internal Allocate JSONB bindings monotonically, including after setParameters() replaces the binding map. */
    public function bindJsonbParameter(string $value): string
    {
        do {
            $name = '__orm_jsonb_' . ++$this->jsonbParameterCounter;
        } while (array_key_exists($name, $this->getParameters()));
        $this->setParameter($name, $value, ParameterType::STRING);
        return ':' . $name;
    }

    /** Append a web-style text search using a registered entity's FullTextIndex. */
    public function andWhereFullText(string $alias, string $search, ?string $index = null): self
    {
        if (trim($search) === '') {
            throw new \InvalidArgumentException('Full-text search requires nonempty user input.');
        }
        $mapping = $this->fullTextIndex($alias, $index);
        if (isset($this->fullTextSearches[$alias][$mapping->name])) {
            throw new \LogicException('A full-text search is already registered for this alias and index. Clone an unsearched base builder for alternatives.');
        }
        $configuration = $this->bindFullTextParameter($mapping->configuration);
        $input = $this->bindFullTextParameter($search);
        $query = 'pg_catalog.websearch_to_tsquery(CAST(' . $configuration . ' AS pg_catalog.regconfig), ' . $input . ')';
        $vector = $alias . '.' . $this->em->getConnection()->getDatabasePlatform()->quoteSingleIdentifier($mapping->column);
        $this->fullTextSearches[$alias][$mapping->name] = ['vector' => $vector, 'query' => $query];
        $this->andWhere($vector . ' @@ ' . $query);
        return $this;
    }

    /** Append rank ordering for a previously registered search. Add a unique tie-breaker separately. */
    public function addOrderByFullTextRank(string $alias, string $direction = 'DESC', ?string $index = null): self
    {
        $direction = strtoupper($direction);
        if (! in_array($direction, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException('Full-text rank order must be ASC or DESC.');
        }
        $this->addOrderBy($this->fullTextRank($alias, $index), $direction);
        return $this;
    }

    /** Add a floating-point score to mixed hydration results without adding an entity property. */
    public function addFullTextRank(string $alias, string $resultAlias = 'rank', ?string $index = null): self
    {
        if (strlen($resultAlias) > 63 || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $resultAlias)
            || in_array($resultAlias, $this->mapping->scalarMappings, true)) {
            throw new \InvalidArgumentException('Full-text rank requires a distinct scalar result alias.');
        }
        $expression = $this->fullTextRank($alias, $index);
        $number = count($this->mapping->scalarMappings);
        do {
            $column = 'orm_fts_rank_' . ++$number;
        } while (isset($this->mapping->columnOwnerMap[$column]) || isset($this->mapping->scalarMappings[$column]));
        return $this->addScalar($expression, $column, $resultAlias, 'float');
    }

    private function fullTextIndex(string $alias, ?string $name): FullTextIndex
    {
        if (! isset($this->entities[$alias], $this->mapping->aliasMap[$alias])) {
            throw new \InvalidArgumentException('Full-text search requires an alias registered with fromEntity() or addJoinedEntity().');
        }
        $indexes = $this->em->getClassMetadata($this->mapping->aliasMap[$alias])->fullTextIndexes;
        if ($name === null && count($indexes) === 1) {
            return $indexes[0];
        }
        foreach ($indexes as $index) {
            if ($index->name === $name) {
                return $index;
            }
        }
        throw new \InvalidArgumentException('Specify an existing FullTextIndex name; omission requires exactly one mapped full-text index.');
    }

    private function fullTextRank(string $alias, ?string $index): string
    {
        $mapping = $this->fullTextIndex($alias, $index);
        $search = $this->fullTextSearches[$alias][$mapping->name]
            ?? throw new \LogicException('Call andWhereFullText() for this alias and index before adding its rank.');
        return 'pg_catalog.ts_rank(' . $search['vector'] . ', ' . $search['query'] . ')';
    }

    private function bindFullTextParameter(string $value): string
    {
        do {
            $name = '__orm_fts_' . ++$this->fullTextParameterCounter;
        } while (array_key_exists($name, $this->getParameters()));
        $this->setParameter($name, $value, ParameterType::STRING);
        return ':' . $name;
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
        $this->customResultSetMapping = true;
        $this->mapping = clone $mapping;
        return $this;
    }

    /** @internal Snapshot the entity SQL and append raw sorting columns for CursorPaginator.
     *  @param non-empty-array<string, 'ASC'|'DESC'> $orderBy
     */
    public function getCursorPaginationSource(array $orderBy): CursorQuery
    {
        $roots = array_diff_key($this->mapping->aliasMap, $this->mapping->parentAliasMap);
        if ($this->cursorUnsupportedOperation || $this->customResultSetMapping || count($roots) !== 1
            || $this->mapping->scalarMappings !== []
            || $this->getFirstResult() !== 0 || $this->getMaxResults() !== null) {
            throw new \InvalidArgumentException('Cursor pagination requires an unlimited entity-only SELECT with one fromEntity() root, without a custom result mapping, UNION, CTEs, writes or row locks.');
        }
        $alias = array_key_first($roots);
        if (! isset($this->entities[$alias]) || ($this->mapping->entityMappings[$alias] ?? null) !== null) {
            throw new \InvalidArgumentException('Cursor pagination requires a root registered by fromEntity().');
        }
        foreach ($this->mapping->relationMap as $joined => $relation) {
            $parent = $this->mapping->parentAliasMap[$joined];
            $parentClass = $this->em->getClassMetadata($this->mapping->aliasMap[$parent]);
            if ($parentClass->getAssociationMapping($relation)->isToMany()) {
                throw new \InvalidArgumentException('Cursor pagination does not support collection fetch joins. Load collections separately.');
            }
        }
        foreach (array_keys($this->getParameters()) as $parameter) {
            if (! is_string($parameter)) {
                throw new \InvalidArgumentException('Cursor pagination requires named parameters.');
            }
        }
        $metadata = $this->em->getClassMetadata($roots[$alias]);
        if (array_key_last($orderBy) !== $metadata->getSingleIdentifierFieldName()) {
            throw new \InvalidArgumentException('Cursor sorting must end with the root primary key as a unique tie-breaker.');
        }
        $source = clone $this;
        $source->resetOrderBy();
        $keys = [];
        foreach ($orderBy as $field => $direction) {
            $mapping = $metadata->fieldMappings[$field] ?? null;
            if ($mapping === null || $mapping->nullable === true || ! in_array($direction, ['ASC', 'DESC'], true)
                || ! in_array($mapping->type, [
                    'smallint', 'integer', 'bigint', 'string', 'text', 'guid', 'boolean', 'decimal', 'float', 'smallfloat',
                    'date', 'date_immutable', 'time', 'time_immutable', 'datetime', 'datetime_immutable', 'datetimetz', 'datetimetz_immutable',
                ], true)) {
                throw new \InvalidArgumentException('Cursor sorting supports non-null built-in scalar fields of the root entity only.');
            }
            $column = 'orm_cursor_key_' . count($keys);
            $source->addSelect($this->column($alias, $field) . ' AS ' . $column);
            $keys[$field] = ['column' => $column, 'direction' => $direction];
        }
        return new CursorQuery($this->em, $metadata, $source->getSQL(), $source->getParameters(), $source->getParameterTypes(), clone $source->mapping, $keys);
    }

    public function insert(string $table): self
    {
        $this->cursorUnsupportedOperation = true;
        parent::insert($table);
        return $this;
    }

    public function update(string $table): self
    {
        $this->cursorUnsupportedOperation = true;
        parent::update($table);
        return $this;
    }

    public function delete(string $table): self
    {
        $this->cursorUnsupportedOperation = true;
        parent::delete($table);
        return $this;
    }

    public function union(string|SqlQueryBuilder $part): self
    {
        $this->cursorUnsupportedOperation = true;
        parent::union($part);
        return $this;
    }

    public function addUnion(string|SqlQueryBuilder $part, UnionType $type = UnionType::DISTINCT): self
    {
        $this->cursorUnsupportedOperation = true;
        parent::addUnion($part, $type);
        return $this;
    }

    public function with(string $name, string|SqlQueryBuilder $part, ?array $columns = null): self
    {
        $this->cursorUnsupportedOperation = true;
        parent::with($name, $part, $columns);
        return $this;
    }

    public function forUpdate(ConflictResolutionMode $conflictResolutionMode = ConflictResolutionMode::ORDINARY): self
    {
        $this->cursorUnsupportedOperation = true;
        parent::forUpdate($conflictResolutionMode);
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
