<?php

declare(strict_types=1);

namespace ModernORM\Batch;

use ModernORM\EntityManagerInterface;
use ModernORM\Cache\Persister\Entity\CachedEntityPersister;
use ModernORM\Exception\EntityNotFoundException;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Query;
use ModernORM\UnitOfWork;
use ModernORM\Statistics\BatchKind;
use ModernORM\Statistics\BatchFallbackReason;
use WeakReference;

/** @internal EntityManager-scoped queue; entries never keep entities alive. */
final class LazyReferenceQueue
{
    public const HINT_TRIGGER = 'modernorm.batchTrigger';
    /** @var array<class-string, array<int, array{reference: WeakReference, identifier: array, size: int}>> */
    private array $pending = [];
    private bool $loading = false;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function add(object $entity, ClassMetadata $class, array $identifier, int|null $batchSize = null): void
    {
        if (! $this->em->isOpen()
            || ! $this->em->isUninitializedObject($entity)
            || $this->em->getUnitOfWork()->getOriginalEntityData($entity) !== []) {
            return;
        }

        $size = $batchSize ?? $this->em->getConfiguration()->getLazyFetchBatchSize();
        $oid = spl_object_id($entity);
        // A shared Identity Map object may be reached through differently configured associations.
        // Keep the strictest observed policy, independent of hydration order (one always opts out).
        $size = min($size, $this->pending[$class->name][$oid]['size'] ?? $size);
        $this->pending[$class->name][$oid] = [
            'reference' => WeakReference::create($entity), 'identifier' => $identifier, 'size' => $size,
        ];
    }

    public function remove(object $entity): void
    {
        unset($this->pending[$entity::class][spl_object_id($entity)]);
        if (($this->pending[$entity::class] ?? []) === []) {
            unset($this->pending[$entity::class]);
        }
    }

    public function clear(): void
    {
        $this->pending = [];
    }

    /** Called from a native ghost initializer. False means use the ordinary single-row loader. */
    public function initialize(object $trigger, ClassMetadata $class): bool
    {
        $entry = $this->pending[$class->name][spl_object_id($trigger)] ?? null;
        $size = $entry['size'] ?? 1;
        $uow = $this->em->getUnitOfWork();
        $reason = match (true) {
            $this->loading => BatchFallbackReason::REENTRANT,
            !$this->em->isOpen() => BatchFallbackReason::MANAGER_CLOSED,
            $uow->getOriginalEntityData($trigger) !== [] => BatchFallbackReason::PARTIAL_REFERENCE,
            $entry === null => BatchFallbackReason::NOT_QUEUED,
            $size === 1 => BatchFallbackReason::SIZE_ONE,
            $uow->tryGetById($entry['identifier'], $class->name) !== $trigger => BatchFallbackReason::UNMANAGED,
            $uow->isScheduledForDelete($trigger) => BatchFallbackReason::PENDING_DELETE,
            default => null,
        };
        if ($reason !== null) {
            $statistics = $this->em->getStatistics();
            if ($statistics?->isEnabled()) {
                $statistics->recordBatchFallback(BatchKind::MANY_TO_ONE, $reason, $class->name, null, $entry === null ? 0 : $size);
            }
            if ($reason === BatchFallbackReason::PARTIAL_REFERENCE || $reason === BatchFallbackReason::UNMANAGED || $reason === BatchFallbackReason::PENDING_DELETE) {
                $this->remove($trigger);
            }
            return false;
        }

        // The trigger is already inside its initializer, so reflection no longer reports it as lazy.
        $selected = [spl_object_id($trigger) => ['entity' => $trigger, 'identifier' => $entry['identifier']]];
        foreach ($this->pending[$class->name] as $oid => $candidate) {
            if ($oid === spl_object_id($trigger)) {
                continue;
            }
            $entity = $candidate['reference']->get();
            if ($entity === null
                || $uow->tryGetById($candidate['identifier'], $class->name) !== $entity
                || ! $this->em->isUninitializedObject($entity)
                || $uow->getOriginalEntityData($entity) !== []
                || $uow->isScheduledForDelete($entity)) {
                unset($this->pending[$class->name][$oid]);
                continue;
            }
            if ($candidate['size'] !== $size) {
                continue;
            }
            $selected[$oid] = ['entity' => $entity, 'identifier' => $candidate['identifier']];
            if (count($selected) === $size) {
                break;
            }
        }

        $field = $class->getSingleIdentifierFieldName();
        $ids = array_map(static fn (array $item): mixed => $item['identifier'][$field], $selected);
        $persister = $uow->getEntityPersister($class->name);
        $statistics = $this->em->getStatistics();
        $measurement = $statistics?->startBatch(BatchKind::MANY_TO_ONE, $class->name, null, $size, count($selected));
        $this->loading = true;
        $failed = true;
        try {
            if ($persister instanceof CachedEntityPersister) {
                $entities = $persister->loadBatchById(array_column($selected, 'identifier'), $trigger, $measurement);
            } else {
                $sql = $persister->getSelectSQL([$field => array_values($ids)]);
                [$params, $types] = $persister->expandParameters([$field => array_values($ids)]);
                $mapping = clone $persister->getResultSetMapping();
                $statement = $this->em->getConnection()->executeQuery($sql, $params, $types);
                $entities = $this->em->newHydrator(Query::HYDRATE_OBJECT)->hydrateAll($statement, $mapping, [
                    self::HINT_TRIGGER => $trigger,
                    UnitOfWork::HINT_DEFEREAGERLOAD => true,
                ]);
            }
            $found = false;
            foreach ($entities as $entity) {
                $this->remove($entity);
                $found = $found || $entity === $trigger;
            }
            if (! $found) {
                $this->remove($trigger);
                throw EntityNotFoundException::fromClassNameAndIdentifier($class->name, $entry['identifier']);
            }
            $failed = false;
            return true;
        } finally {
            $this->loading = false;
            if ($measurement !== null) {
                $statistics->recordBatch($measurement, $failed);
            }
        }
    }
}
