<?php

declare(strict_types=1);

namespace ModernORM;

/** A PostgreSQL SELECT with an explicit ORM result mapping. No query language is parsed. */
class Query extends NativeQuery
{
    public const HINT_REFRESH = 'doctrine.refresh';
    public const HINT_REFRESH_ENTITY = 'doctrine.refresh.entity';
    public const HINT_FORCE_PARTIAL_LOAD = 'doctrine.forcePartialLoad';
    public const HINT_INCLUDE_META_COLUMNS = 'doctrine.includeMetaColumns';
    public const HINT_READ_ONLY = 'doctrine.readOnly';
    public const HINT_INTERNAL_ITERATION = 'doctrine.internal.iteration';
    public const HINT_LOCK_MODE = 'doctrine.lockMode';
    public const HINT_PARTIAL = 'modernorm.partial';

    private int $firstResult = 0;
    private ?int $maxResults = null;

    public function setFirstResult(int $offset): static
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('Offset must be non-negative.');
        }
        $this->firstResult = $offset;
        return $this;
    }

    public function getFirstResult(): int
    {
        return $this->firstResult;
    }

    public function setMaxResults(?int $limit): static
    {
        if ($limit !== null && $limit < 0) {
            throw new \InvalidArgumentException('Limit must be non-negative.');
        }
        $this->maxResults = $limit;
        return $this;
    }

    public function getMaxResults(): ?int
    {
        return $this->maxResults;
    }

    public function getSQL(): string
    {
        if ($this->firstResult !== 0 || $this->maxResults !== null) {
            $mapping = $this->getResultSetMapping();
            foreach ($mapping?->relationMap ?? [] as $alias => $relation) {
                $parent = $mapping->parentAliasMap[$alias];
                if ($this->em->getClassMetadata($mapping->aliasMap[$parent])->getAssociationMapping($relation)->isToMany()) {
                    throw new \LogicException('Collection fetch joins require explicit root-ID pagination, not SQL row limits.');
                }
            }
        }
        return $this->em->getConnection()->getDatabasePlatform()->modifyLimitQuery(
            parent::getSQL(),
            $this->maxResults,
            $this->firstResult,
        );
    }
}
