<?php

declare(strict_types=1);

namespace ModernORM\Batch;

use ModernORM\EntityManagerInterface;
use ModernORM\Mapping\AssociationMapping;
use ModernORM\Mapping\ToManyAssociationMapping;
use ModernORM\PersistentCollection;
use ModernORM\Statistics\BatchKind;
use ModernORM\Statistics\BatchFallbackReason;
use WeakReference;

/** @internal Lazy collection queues are scoped to one EntityManager and association role. */
final class LazyCollectionQueue
{
    /** @var array<string, array<int, WeakReference>> */
    private array $pending = [];
    private bool $loading = false;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function add(PersistentCollection $collection): void
    {
        $mapping = $collection->getMapping();
        if (! $this->eligible($collection)) {
            return;
        }
        $size = $mapping->batchSize ?? $this->em->getConfiguration()->getLazyFetchBatchSize();
        if ($size === 1) {
            return;
        }
        $owner = $collection->getOwner();
        $this->pending[$this->key($mapping, $size)][spl_object_id($owner)] = WeakReference::create($collection);
    }

    public function removeOwner(object $owner): void
    {
        foreach (array_keys($this->pending) as $key) {
            unset($this->pending[$key][spl_object_id($owner)]);
            if ($this->pending[$key] === []) {
                unset($this->pending[$key]);
            }
        }
    }

    public function clear(): void
    {
        $this->pending = [];
    }

    public function initialize(PersistentCollection $trigger): bool
    {
        $mapping = $trigger->getMapping();
        $reason = $this->loading ? BatchFallbackReason::REENTRANT : $this->exclusionReason($trigger);
        if ($reason !== null) {
            $this->fallback($trigger, $reason);
            return false;
        }
        $size = $mapping->batchSize ?? $this->em->getConfiguration()->getLazyFetchBatchSize();
        $key = $this->key($mapping, $size);
        $owner = $trigger->getOwner();
        $entry = $this->pending[$key][spl_object_id($owner)] ?? null;
        if ($size === 1 || $entry === null || $entry->get() !== $trigger) {
            $this->fallback($trigger, $size === 1 ? BatchFallbackReason::SIZE_ONE : BatchFallbackReason::NOT_QUEUED);
            return false;
        }

        $selected = [spl_object_id($owner) => $trigger];
        foreach ($this->pending[$key] as $oid => $candidate) {
            if ($oid === spl_object_id($owner)) {
                continue;
            }
            $collection = $candidate->get();
            if ($collection === null || ! $this->eligible($collection)) {
                unset($this->pending[$key][$oid]);
                continue;
            }
            $selected[$oid] = $collection;
            if (count($selected) === $size) {
                break;
            }
        }

        $owners = array_map(static fn (PersistentCollection $collection): object => $collection->getOwner(), $selected);
        $uow = $this->em->getUnitOfWork();
        $statistics = $this->em->getStatistics();
        $measurement = $statistics?->isEnabled()
            ? $statistics->startBatch(
                $mapping->isManyToMany() ? BatchKind::MANY_TO_MANY : BatchKind::ONE_TO_MANY,
                $mapping->targetEntity,
                $mapping->sourceEntity . '::' . $mapping->fieldName,
                $size,
                count($selected)
            )
            : null;
        $this->loading = true;
        $failed = true;
        try {
            if ($mapping->isManyToMany()) {
                $rows = (new ManyToManyBatchLoader($this->em))->load($mapping, $selected);
            } elseif ($mapping->isOneToMany()) {
                $found = $uow->getEntityPersister($mapping->targetEntity)->loadAll(
                    [$mapping->mappedBy => array_values($owners)],
                    $mapping->orderBy(),
                );
                $target = $this->em->getClassMetadata($mapping->targetEntity);
                $parentProperty = $target->getPropertyAccessor($mapping->mappedBy);
                $indexProperty = $mapping->isIndexed() ? $target->getPropertyAccessor($mapping->indexBy()) : null;
                $rows = array_fill_keys(array_keys($selected), []);
                foreach ($found as $entity) {
                    if ($uow->isScheduledForDelete($entity)) {
                        continue;
                    }
                    // Preserve a managed child's local FK change instead of overwriting its back reference.
                    $parent = $parentProperty->getValue($entity);
                    if ($parent === null || ! isset($selected[spl_object_id($parent)])) {
                        continue;
                    }
                    $oid = spl_object_id($parent);
                    if ($indexProperty !== null) {
                        $rows[$oid][$indexProperty->getValue($entity)] = $entity;
                    } else {
                        $rows[$oid][] = $entity;
                    }
                }
            } else {
                throw new \LogicException('Collection batching requires OneToMany or ManyToMany mapping.');
            }
            foreach ($selected as $oid => $collection) {
                // PostLoad can explicitly initialize, replace, or detach a collection while rows hydrate.
                if ($this->eligible($collection)) {
                    $collection->initializeFromBatch($rows[$oid]);
                }
                unset($this->pending[$key][$oid]);
            }
            if (($this->pending[$key] ?? []) === []) {
                unset($this->pending[$key]);
            }
            $failed = false;
            $initialized = $trigger->isInitialized();
            if (!$initialized) {
                $this->fallback($trigger, $this->exclusionReason($trigger) ?? BatchFallbackReason::NOT_QUEUED);
            }
            return $initialized;
        } finally {
            $this->loading = false;
            if ($measurement !== null) {
                $statistics->recordBatch($measurement, $failed);
            }
        }
    }

    private function key(AssociationMapping&ToManyAssociationMapping $mapping, int $size): string
    {
        return $mapping->sourceEntity . '#' . $mapping->fieldName . '#' . $size;
    }

    private function eligible(PersistentCollection $collection): bool
    {
        return $this->exclusionReason($collection) === null;
    }

    private function fallback(PersistentCollection $collection, BatchFallbackReason $reason): void
    {
        $statistics = $this->em->getStatistics();
        if (!$statistics?->isEnabled()) {
            return;
        }
        $mapping = $collection->getMapping();
        $statistics->recordBatchFallback(
            $mapping->isManyToMany() ? BatchKind::MANY_TO_MANY : BatchKind::ONE_TO_MANY,
            $reason,
            $mapping->targetEntity,
            $mapping->sourceEntity . '::' . $mapping->fieldName,
            $mapping->batchSize ?? $this->em->getConfiguration()->getLazyFetchBatchSize(),
        );
    }

    private function exclusionReason(PersistentCollection $collection): ?BatchFallbackReason
    {
        if ($collection->isInitialized()) {
            return BatchFallbackReason::ALREADY_INITIALIZED;
        }
        if (!$this->em->isOpen()) {
            return BatchFallbackReason::MANAGER_CLOSED;
        }
        $mapping = $collection->getMapping();
        if ($this->em->getCache() !== null && $this->em->getClassMetadata($mapping->targetEntity)->cache !== null) {
            return BatchFallbackReason::CACHED_TARGET;
        }
        $owner = $collection->getOwner();
        if ($owner === null || $this->em->isUninitializedObject($owner)) {
            return BatchFallbackReason::UNMANAGED;
        }
        if ($mapping->isManyToMany()) {
            $owning = $this->em->getMetadataFactory()->getOwningSide($mapping);
            if (count($owning->joinTable->joinColumns) !== 1 || count($owning->joinTable->inverseJoinColumns) !== 1) {
                return BatchFallbackReason::UNSUPPORTED_JOIN;
            }
        }
        $class = $this->em->getClassMetadata($mapping->sourceEntity);
        $uow = $this->em->getUnitOfWork();
        if ($uow->tryGetById($class->getIdentifierValues($owner), $class->name) !== $owner) {
            return BatchFallbackReason::UNMANAGED;
        }
        if ($uow->isScheduledForDelete($owner)) {
            return BatchFallbackReason::PENDING_DELETE;
        }
        if ($class->getPropertyAccessor($mapping->fieldName)->getValue($owner) !== $collection) {
            return BatchFallbackReason::REPLACED_COLLECTION;
        }
        return null;
    }
}
