<?php

declare(strict_types=1);

namespace ModernORM\Statistics;

/** Planned work at a phase boundary, not affected database row counts. */
final readonly class UnitOfWorkDetails
{
    public function __construct(
        public UnitOfWorkPhase $phase,
        public int $managedEntities,
        public int $insertions,
        public int $updates,
        public int $deletions,
        public int $collectionUpdates,
        public int $collectionDeletions,
        public int $extraUpdates,
    ) {
    }
}
