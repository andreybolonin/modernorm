<?php

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

use InvalidArgumentException;
use ModernORM\Internal\StronglyConnectedComponents;
use ModernORM\Internal\TopologicalSort;

use function strtolower;

/** @internal Computes entity INSERT/DELETE order without reading ORM state or executing writes. */
final class WriteOrderPlanner
{
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
