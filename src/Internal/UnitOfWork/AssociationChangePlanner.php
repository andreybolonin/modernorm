<?php

/*
 * This file is part of the ModernORM package.
 *
 * (c) Andrey Bolonin <andreybolonin1989@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

use function spl_object_id;

/** @internal Tracks orphan candidates and decides removals from supplied membership facts, without ORM state access. */
final class AssociationChangePlanner
{
    /** @var array<int, object> */
    private array $candidates = [];
    /** @var array<int, list<object>> Opaque origins are resolved by the ORM adapter. */
    private array $origins = [];
    /** @var array<int, array<string, OrphanRemovalReason>> */
    private array $reasons = [];

    public function schedule(object $entity, OrphanRemovalReason $reason, ?object $origin = null): void
    {
        $id = spl_object_id($entity);
        $this->candidates[$id] = $entity;
        $this->reasons[$id][$reason->value] = $reason;
        if ($origin !== null) {
            $this->origins[$id][] = $origin;
        }
    }

    public function cancel(object $entity): void
    {
        $id = spl_object_id($entity);
        unset($this->candidates[$id], $this->origins[$id], $this->reasons[$id]);
    }

    public function hasCandidates(): bool
    {
        return $this->candidates !== [];
    }

    /** @return array<int, object> A snapshot preserves insertion order during lifecycle callbacks. */
    public function candidates(): array
    {
        return $this->candidates;
    }

    /** @return list<object> */
    public function originsFor(object $entity): array
    {
        return $this->origins[spl_object_id($entity)] ?? [];
    }

    /** @return list<OrphanRemovalReason> */
    public function reasonsFor(object $entity): array
    {
        return array_values($this->reasons[spl_object_id($entity)] ?? []);
    }

    /**
     * Observations may be provided lazily to preserve lifecycle and collection-loading order.
     * The planner never initializes a collection or reads an entity property.
     *
     * @param iterable<AssociationMembership> $memberships
     */
    public function isOrphan(iterable $memberships): bool
    {
        foreach ($memberships as $membership) {
            if ($membership->containsCandidate
                || ($membership->owningSideOwner !== null && $membership->owningSideOwner !== $membership->originalOwner)) {
                return false;
            }
        }
        return true;
    }

    public function clear(): void
    {
        $this->candidates = $this->origins = $this->reasons = [];
    }

    /**
     * @param array<array-key, object> $before
     * @param array<array-key, object> $after
     * @return list<object>
     */
    public static function removedElements(array $before, array $after): array
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
