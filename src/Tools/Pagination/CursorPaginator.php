<?php

/*
 * Page navigation adapted from Doctrine ORM. Copyright (c) Doctrine Project.
 * MIT license: licenses/Doctrine-ORM-MIT.txt. SQL integration is specific to ModernORM.
 */

declare(strict_types=1);

namespace ModernORM\Tools\Pagination;

use ModernORM\QueryBuilder;
use ModernORM\Tools\Pagination\Exception\InvalidCursor;

/** Stateless keyset paginator; sorting uses root PHP field names and ends in the primary key. */
final class CursorPaginator
{
    /** @var non-empty-array<string, 'ASC'|'DESC'> */
    private readonly array $orderBy;

    /** @param non-empty-array<string, 'ASC'|'DESC'> $orderBy */
    public function __construct(private readonly int $limit, array $orderBy)
    {
        if ($limit < 1 || $limit === PHP_INT_MAX || $orderBy === []) {
            throw new \InvalidArgumentException('Cursor pagination requires a positive limit and explicit sorting fields.');
        }
        foreach ($orderBy as $field => &$direction) {
            if (! is_string($field) || ! is_string($direction) || ! in_array(strtoupper($direction), ['ASC', 'DESC'], true)) {
                throw new \InvalidArgumentException('Cursor sorting requires mapped field names and ASC/DESC directions.');
            }
            $direction = strtoupper($direction);
        }
        unset($direction);
        $this->orderBy = $orderBy;
    }

    /** @return CursorPage<object> */
    public function paginate(QueryBuilder $query, mixed $position = null): CursorPage
    {
        $cursor = match (true) {
            $position instanceof Cursor => $position,
            $position === null || $position === '' => null,
            is_string($position) => Cursor::fromEncodedString($position),
            default => throw new InvalidCursor('Expected a Cursor, encoded string, or null.'),
        };
        $source = $query->getCursorPaginationSource($this->orderBy);
        $rows = $source->keyQuery($cursor)->setMaxResults($this->limit + 1)->fetchAllAssociative();
        $hasMore = count($rows) > $this->limit;
        $rows = array_slice($rows, 0, $this->limit);
        if ($cursor?->isPrevious()) {
            $rows = array_reverse($rows);
        }
        [$items, $snapshots] = $source->hydrate($rows);
        return new CursorPage(
            $items,
            $hasMore,
            $cursor,
            $source->count(...),
            static function (mixed $item, bool $isNext) use ($snapshots): Cursor {
                if (! is_object($item) || ! $snapshots->offsetExists($item)) {
                    throw new \InvalidArgumentException('Cursor items must belong to this page.');
                }
                return new Cursor($snapshots[$item], $isNext);
            },
        );
    }

    public function getLimit(): int
    {
        return $this->limit;
    }
}
