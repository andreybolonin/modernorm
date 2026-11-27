<?php

/*
 * This file is part of the ModernORM package.
 *
 * (c) Andrey Bolonin <andreybolonin1989@gmail.com>
 * (c) Doctrine Project
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

use Closure;
use InvalidArgumentException;
use ModernORM\Internal\StronglyConnectedComponents;
use ModernORM\Internal\TopologicalSort;

use function strtolower;
use function strcmp;
use function usort;

/** @internal Computes entity INSERT/UPDATE/DELETE order without reading ORM state or executing writes. */
final class WriteOrderPlanner
{
    /**
     * Order by exact runtime class, then lexicographically by identifier hash.
     * Resolve hashes only when comparing same-class entities; ties retain scheduling order.
     *
     * @param array<int, object> $entities
     * @param Closure(object): string $identifierHash
     * @return list<object>
     */
    public function orderUpdates(array $entities, Closure $identifierHash): array
    {
        usort($entities, static function (object $a, object $b) use ($identifierHash): int {
            $result = strcmp($a::class, $b::class);
            if ($result !== 0) {
                return $result;
            }
            // Deliberately lexical: numeric strings may be ordered as 1, 100, 2.
            return strcmp($identifierHash($a), $identifierHash($b));
        });
        return $entities;
    }

    /**
     * Use the same lexical order and stable ties for ordinary single-field identifiers.
     * An unsupported identifier leaves validation and ordering to orderUpdates().
     *
     * @param array<int, object> $entities
     * @param array<int, array<string, mixed>> $identifiers
     * @return list<object>|null
     */
    public function orderUpdatesUsingStoredIdentifiers(array $entities, array $identifiers): ?array
    {
        $hashes = [];
        foreach ($entities as $oid => $entity) {
            $identifier = $identifiers[$oid] ?? [];
            $value = count($identifier) === 1 ? reset($identifier) : null;
            if (! is_int($value) && ! is_string($value)) {
                return null;
            }
            $hashes[$entity::class][$oid] = $value;
        }

        ksort($hashes, SORT_STRING);
        $ordered = [];
        foreach ($hashes as $group) {
            asort($group, SORT_STRING);
            foreach ($group as $oid => $_) {
                $ordered[] = $entities[$oid];
            }
        }

        return $ordered;
    }

    /**
     * @param array<int, object> $entities
     * @param iterable<InsertDependency> $dependencies
     * @return list<object>
     */
    public function orderInserts(array $entities, iterable $dependencies): array
    {
        $sort = new TopologicalSort();
        foreach ($entities as $entity) {
            $sort->addNode($entity);
        }
        foreach ($dependencies as $dependency) {
            if (! $sort->hasNode($dependency->source) || ! $sort->hasNode($dependency->target)) {
                continue;
            }
            if ($dependency->source === $dependency->target && $dependency->assignedIdentifier) {
                continue;
            }
            $sort->addEdge($dependency->source, $dependency->target, $dependency->nullable);
        }
        return $sort->sort();
    }

    /**
     * The separate observations retain the existing two-pass cascade/dependency read order.
     *
     * @param array<int, object> $entities
     * @param iterable<DeleteDependency> $cascadeDependencies
     * @param iterable<EntityDeleteDependencies> $dependencies Groups follow entity scheduling order.
     * @return list<object>
     */
    public function orderDeletes(array $entities, iterable $cascadeDependencies, iterable $dependencies): array
    {
        $components = new StronglyConnectedComponents();
        $sort = new TopologicalSort();
        foreach ($entities as $entity) {
            $components->addNode($entity);
            $sort->addNode($entity);
        }
        foreach ($cascadeDependencies as $dependency) {
            if (strtolower($dependency->onDelete ?? '') !== 'cascade'
                || ! $sort->hasNode($dependency->source) || ! $sort->hasNode($dependency->target)) {
                continue;
            }
            $components->addEdge($dependency->source, $dependency->target);
        }
        $components->findStronglyConnectedComponents();

        foreach ($dependencies as $group) {
            $entity = $group->entity;
            $component = $components->getNodeRepresentingStronglyConnectedComponent($entity);
            if ($component !== $entity) {
                $sort->addEdge($entity, $component, false);
            }
            foreach ($group->dependencies as $dependency) {
                if ($dependency->source !== $entity) {
                    throw new InvalidArgumentException('Delete dependency source must match its entity group.');
                }
                if (strtolower($dependency->onDelete ?? '') === 'set null' || ! $sort->hasNode($dependency->target)) {
                    continue;
                }
                $targetComponent = $components->getNodeRepresentingStronglyConnectedComponent($dependency->target);
                if ($targetComponent !== $component) {
                    $sort->addEdge($targetComponent, $component, false);
                }
            }
        }
        return $sort->sort();
    }
}
