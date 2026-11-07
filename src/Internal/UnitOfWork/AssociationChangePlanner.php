<?php

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

use function spl_object_id;

/** @internal Computes membership changes without scheduling writes or executing SQL. */
final class AssociationChangePlanner
{
    /**
     * @param iterable<object> $before
     * @param iterable<object> $after
     * @return list<object>
     */
    public static function removedElements(iterable $before, iterable $after): array
    {
        $remaining = [];
        foreach ($after as $entity) {
            $remaining[spl_object_id($entity)] = true;
        }
        $removed = [];
        foreach ($before as $entity) {
            $id = spl_object_id($entity);
            if (! isset($remaining[$id])) {
                $removed[$id] = $entity;
            }
        }
        return array_values($removed);
    }
}
