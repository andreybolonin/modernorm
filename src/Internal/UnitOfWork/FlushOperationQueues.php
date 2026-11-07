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

use ModernORM\PersistentCollection;

use function spl_object_id;

/** @internal Owns pending flush work; never executes SQL, callbacks or collection mutations. */
final class FlushOperationQueues
{
    /** @var array<int, object> */
    private array $insertions = [];

    /** @var array<int, object> */
    private array $updates = [];

    /** @var array<int, object> */
    private array $deletions = [];

    /** @var array<int, PersistentCollection<array-key, object>> */
    private array $collectionUpdates = [];

    /** @var array<int, PersistentCollection<array-key, object>> */
    private array $collectionDeletions = [];

    /** @var array<int, array{object, array<string, array{mixed, mixed}>}> */
    private array $extraUpdates = [];

    /** @var array<int, PersistentCollection<array-key, object>> */
    private array $visitedCollections = [];

    /** @var array<int, array<array-key, true>> */
    private array $pendingCollectionElementRemovals = [];

    /** @return array<int, object> */
    public function insertions(): array
    {
        return $this->insertions;
    }

    public function hasInsertion(int $oid): bool
    {
        return isset($this->insertions[$oid]);
    }

    public function addInsertion(object $value): void
    {
        $this->insertions[spl_object_id($value)] = $value;
    }

    public function removeInsertion(int $oid): void
    {
        unset($this->insertions[$oid]);
    }

    /** @return array<int, object> */
    public function updates(): array
    {
        return $this->updates;
    }

    public function hasUpdate(int $oid): bool
    {
        return isset($this->updates[$oid]);
    }

    public function addUpdate(object $value): void
    {
        $this->updates[spl_object_id($value)] = $value;
    }

    public function removeUpdate(int $oid): void
    {
        unset($this->updates[$oid]);
    }

    /** @return array<int, object> */
    public function deletions(): array
    {
        return $this->deletions;
    }

    public function hasDeletion(int $oid): bool
    {
        return isset($this->deletions[$oid]);
    }

    public function addDeletion(object $value): void
    {
        $this->deletions[spl_object_id($value)] = $value;
    }

    public function removeDeletion(int $oid): void
    {
        unset($this->deletions[$oid]);
    }

    /** @return array<int, PersistentCollection<array-key, object>> */
    public function collectionUpdates(): array
    {
        return $this->collectionUpdates;
    }

    /** @param PersistentCollection<array-key, object> $value */
    public function addCollectionUpdate(PersistentCollection $value): void
    {
        $this->collectionUpdates[spl_object_id($value)] = $value;
    }

    private function removeCollectionUpdate(int $oid): void
    {
        unset($this->collectionUpdates[$oid]);
    }

    /** @return array<int, PersistentCollection<array-key, object>> */
    public function collectionDeletions(): array
    {
        return $this->collectionDeletions;
    }

    public function hasCollectionDeletion(int $oid): bool
    {
        return isset($this->collectionDeletions[$oid]);
    }

    /** @param PersistentCollection<array-key, object> $value */
    public function addCollectionDeletion(PersistentCollection $value): void
    {
        $this->collectionDeletions[spl_object_id($value)] = $value;
    }

    /** Cancel entity writes on detach without changing the existing extra-update policy. */
    public function removeEntity(int $oid): void
    {
        unset($this->insertions[$oid], $this->updates[$oid], $this->deletions[$oid]);
    }

    /** @param PersistentCollection<array-key, object> $collection */
    public function scheduleCollectionDeletion(PersistentCollection $collection): void
    {
        $this->removeCollectionUpdate(spl_object_id($collection));
        $this->addCollectionDeletion($collection);
    }

    /** @param array<string, array{mixed, mixed}> $changeset */
    public function addExtraUpdate(object $entity, array $changeset): void
    {
        $oid = spl_object_id($entity);
        if (isset($this->extraUpdates[$oid])) {
            $changeset += $this->extraUpdates[$oid][1];
        }
        $this->extraUpdates[$oid] = [$entity, $changeset];
    }

    /** @return array<int, array{object, array<string, array{mixed, mixed}>}> */
    public function extraUpdates(): array
    {
        return $this->extraUpdates;
    }

    public function clearExtraUpdates(): void
    {
        $this->extraUpdates = [];
    }

    /** @param PersistentCollection<array-key, object> $collection */
    public function visitCollection(PersistentCollection $collection): void
    {
        $this->visitedCollections[spl_object_id($collection)] = $collection;
    }

    /** @return array<int, PersistentCollection<array-key, object>> */
    public function visitedCollections(): array
    {
        return $this->visitedCollections;
    }

    /** @param PersistentCollection<array-key, object> $collection */
    public function removeCollectionElementAfterCommit(PersistentCollection $collection, string|int $key): void
    {
        $oid = spl_object_id($collection);
        $this->visitCollection($collection);
        $this->pendingCollectionElementRemovals[$oid][$key] = true;
    }

    /** @return array<array-key, true> */
    public function pendingCollectionElementRemovals(int $oid): array
    {
        return $this->pendingCollectionElementRemovals[$oid] ?? [];
    }

    /** Extra updates and collection synchronization alone do not start a flush transaction. */
    public function hasPrimaryOperations(): bool
    {
        return (bool) ($this->insertions || $this->updates || $this->deletions
            || $this->collectionUpdates || $this->collectionDeletions);
    }

    public function clear(): void
    {
        $this->insertions = $this->updates = $this->deletions = $this->extraUpdates =
        $this->collectionUpdates = $this->collectionDeletions = $this->visitedCollections =
        $this->pendingCollectionElementRemovals = [];
    }
}
