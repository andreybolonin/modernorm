<?php

declare(strict_types=1);

namespace ModernORM\Tools\Pagination;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Query\QueryBuilder as SqlQueryBuilder;
use ModernORM\Query;

/**
 * Explicit two-phase SQL pagination. The root query must return one unique ID column
 * in deterministic page order. The hydration query must filter on the reserved ID parameter.
 * Both queries are supplied by the caller; no SQL is parsed or rewritten into an ORM language.
 *
 * @implements \IteratorAggregate<array-key, object>
 */
final class Paginator implements \Countable, \IteratorAggregate
{
    private Query $query;
    private SqlQueryBuilder $roots;

    public function __construct(Query $query, SqlQueryBuilder $roots, private readonly string $idParameter = '__orm_page_ids')
    {
        if ($query->getFirstResult() !== 0 || $query->getMaxResults() !== null) {
            throw new \InvalidArgumentException('Apply page limits to the root SQL builder, not the hydration query.');
        }
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $idParameter)
            || ! preg_match('/:' . preg_quote($idParameter, '/') . '\\b/', $query->getSQL())) {
            throw new \InvalidArgumentException('Hydration SQL must include the page-ID parameter.');
        }
        $this->query = clone $query;
        $this->roots = clone $roots;
    }

    public function count(): int
    {
        $roots = clone $this->roots;
        $roots->setFirstResult(0)->setMaxResults(null);
        return (int) $this->query->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM (' . $roots->getSQL() . ') modernorm_roots',
            $roots->getParameters(),
            $roots->getParameterTypes(),
        );
    }

    public function getIterator(): \Traversable
    {
        $rows = $this->roots->executeQuery()->fetchAllAssociative();
        if ($rows === []) {
            return new \ArrayIterator([]);
        }
        $ids = [];
        foreach ($rows as $row) {
            if (count($row) !== 1 || current($row) === null) {
                throw new \LogicException('Root SQL must return one non-null identifier column per root.');
            }
            $ids[] = current($row);
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new \LogicException('Root SQL must return unique identifiers.');
        }
        $query = clone $this->query;
        $type = array_all($ids, static fn (mixed $id): bool => is_int($id)) ? ArrayParameterType::INTEGER : ArrayParameterType::STRING;
        $query->setParameter($this->idParameter, $ids, $type);
        return new \ArrayIterator($query->getResult());
    }
}
