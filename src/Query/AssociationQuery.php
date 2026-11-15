<?php

declare(strict_types=1);

namespace ModernORM\Query;

use BackedEnum;
use ModernORM\EntityManagerInterface;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\ManyToManyOwningSideMapping;
use ModernORM\Mapping\OneToManyAssociationMapping;
use ModernORM\QueryBuilder;
use ModernORM\Tools\Pagination\Cursor;
use ModernORM\Tools\Pagination\CursorPage;
use ModernORM\Tools\Pagination\CursorPaginator;

/** Immutable database queries scoped to a persisted owner's to-many association. */
final class AssociationQuery
{
    private QueryBuilder $query;
    private readonly ClassMetadata $target;
    /** @var array<string, 'ASC'|'DESC'> */
    private array $ordering = [];
    private int $parameter = 0;

    public function __construct(private readonly EntityManagerInterface $em, object $owner, string $field)
    {
        if (! $em->contains($owner) || $em->getUnitOfWork()->isScheduledForInsert($owner)) {
            throw new \InvalidArgumentException('Association queries require a persisted owner managed by this EntityManager.');
        }
        $source = $em->getClassMetadata($owner::class);
        $association = $source->getAssociationMapping($field);
        if (! $association->isToMany()) {
            throw new \InvalidArgumentException('Association queries require a to-many association.');
        }
        $this->target = $em->getClassMetadata($association->targetEntity);
        $source->assertSupportedMapping();
        $this->target->assertSupportedMapping();
        $sourceId = $source->getSingleIdentifierFieldName();
        $targetId = $this->target->getSingleIdentifierFieldName();
        $id = $em->getUnitOfWork()->getEntityIdentifier($owner)[$sourceId] ?? null;
        if ($id === null) {
            throw new \InvalidArgumentException('Association query owner has no persisted identifier.');
        }
        $platform = $em->getConnection()->getDatabasePlatform();
        $quote = $em->getConfiguration()->getQuoteStrategy();
        $this->query = $em->createQueryBuilder()->fromEntity($this->target->name, 'aq_target');
        $this->query->setParameter('aq_owner', $id, $source->getTypeOfField($sourceId));
        if ($association instanceof OneToManyAssociationMapping) {
            $owning = $this->target->getAssociationMapping($association->mappedBy);
            if (! $owning->isToOneOwningSide() || count($owning->joinColumns) !== 1
                || $owning->joinColumns[0]->referencedColumnName !== $source->getColumnName($sourceId)) {
                throw new \InvalidArgumentException('Association queries require a single-column primary-key join.');
            }
            $column = $quote->getJoinColumnName($owning->joinColumns[0], $this->target, $platform);
            $this->query->andWhere('aq_target.' . $column . ' = :aq_owner');
        } else {
            $owning = $em->getMetadataFactory()->getOwningSide($association);
            if (! $owning instanceof ManyToManyOwningSideMapping) {
                throw new \InvalidArgumentException('Unsupported association query mapping.');
            }
            $owningClass = $em->getClassMetadata($owning->sourceEntity);
            $sourceColumns = $association->isOwningSide() ? $owning->joinTable->joinColumns : $owning->joinTable->inverseJoinColumns;
            $targetColumns = $association->isOwningSide() ? $owning->joinTable->inverseJoinColumns : $owning->joinTable->joinColumns;
            if (count($sourceColumns) !== 1 || count($targetColumns) !== 1
                || $sourceColumns[0]->referencedColumnName !== $source->getColumnName($sourceId)
                || $targetColumns[0]->referencedColumnName !== $this->target->getColumnName($targetId)) {
                throw new \InvalidArgumentException('Association queries require single-column primary-key joins.');
            }
            $table = $quote->getJoinTableName($owning, $owningClass, $platform);
            $sourceColumn = $quote->getJoinColumnName($sourceColumns[0], $owningClass, $platform);
            $targetColumn = $quote->getJoinColumnName($targetColumns[0], $owningClass, $platform);
            $this->query->andWhere('EXISTS (SELECT 1 FROM ' . $table . ' aq_link WHERE aq_link.' . $sourceColumn . ' = :aq_owner AND aq_link.' . $targetColumn . ' = ' . $this->column($targetId) . ')');
        }
        foreach ($association->orderBy() as $sortField => $direction) {
            $this->ordering[$sortField] = $direction instanceof \SortDirection
                ? ($direction === \SortDirection::Ascending ? 'ASC' : 'DESC') : strtoupper($direction);
        }
    }

    /** Add an AND predicate using a target PHP field name and a bound value. */
    public function where(string $field, mixed $value, string $operator = '='): self
    {
        $column = $this->column($field);
        $operator = strtoupper($operator);
        if (! in_array($operator, ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'ILIKE'], true)) {
            throw new \InvalidArgumentException('Unsupported association query comparison operator.');
        }
        $copy = clone $this;
        if ($value === null) {
            if (! in_array($operator, ['=', '!=', '<>'], true)) {
                throw new \InvalidArgumentException('NULL supports equality or inequality only.');
            }
            $copy->query->andWhere($column . ($operator === '=' ? ' IS NULL' : ' IS NOT NULL'));
        } else {
            $name = 'aq_filter_' . $copy->parameter++;
            $copy->query->andWhere($column . ' ' . $operator . ' :' . $name);
            $copy->query->setParameter($name, $value instanceof BackedEnum ? $value->value : $value, $this->target->getTypeOfField($field));
        }
        return $copy;
    }

    /** Replace ordering with one explicit target field. */
    public function orderBy(string $field, string $direction = 'ASC'): self
    {
        $this->column($field);
        $direction = strtoupper($direction);
        if (! in_array($direction, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException('Association query ordering requires ASC or DESC.');
        }
        $copy = clone $this;
        $copy->ordering = [$field => $direction];
        return $copy;
    }

    public function addOrderBy(string $field, string $direction = 'ASC'): self
    {
        $copy = $this->orderBy($field, $direction);
        $copy->ordering = [...$this->ordering, ...$copy->ordering];
        return $copy;
    }

    /** SQL COUNT; ignores local collection edits and performs no hydration or flush. */
    public function count(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM (' . $this->query->getSQL() . ') aq_count', $this->query->getParameters(), $this->query->getParameterTypes());
    }

    /** Membership by target identifier, with the same filters as count/page. */
    public function exists(mixed $id): bool
    {
        if (! is_scalar($id) && ! $id instanceof BackedEnum) {
            throw new \InvalidArgumentException('Association membership requires a scalar target identifier.');
        }
        $query = clone $this->query;
        $field = $this->target->getSingleIdentifierFieldName();
        $query->andWhere($this->column($field) . ' = :aq_member')->setParameter('aq_member', $id instanceof BackedEnum ? $id->value : $id, $this->target->getTypeOfField($field));
        $result = $this->em->getConnection()->fetchOne('SELECT EXISTS (' . $query->getSQL() . ')', $query->getParameters(), $query->getParameterTypes());
        return in_array($result, [true, 1, '1', 't'], true);
    }

    /** @return CursorPage<object> */
    public function page(int $limit = 50, Cursor|string|null $after = null): CursorPage
    {
        $order = $this->ordering;
        $id = $this->target->getSingleIdentifierFieldName();
        $direction = $order[$id] ?? 'ASC';
        unset($order[$id]);
        $order[$id] = $direction;
        return (new CursorPaginator($limit, $order))->paginate($this->query, $after);
    }

    private function column(string $field): string
    {
        if (! $this->target->hasField($field)) {
            throw new \InvalidArgumentException('Association queries require a mapped scalar target field: ' . $field);
        }
        return 'aq_target.' . $this->em->getConfiguration()->getQuoteStrategy()->getColumnName($field, $this->target, $this->em->getConnection()->getDatabasePlatform());
    }

    public function __clone()
    {
        $this->query = clone $this->query;
    }
}
