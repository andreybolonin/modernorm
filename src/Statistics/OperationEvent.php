<?php

declare(strict_types=1);

namespace ModernORM\Statistics;

/** Immutable values only: no SQL, parameters, identifiers, entities or exceptions. */
final readonly class OperationEvent
{
    public function __construct(
        public OperationKind $kind,
        public int $startedAtUnixNanoseconds,
        public int $durationNanoseconds,
        public bool $failed,
        public ?BatchKind $batchKind = null,
        public ?string $entityClass = null,
        public ?string $association = null,
        public int $configuredSize = 0,
        public int $selectedReferences = 0,
        public int $selectedCollections = 0,
        public int $cacheHits = 0,
        public int $cacheMisses = 0,
        public int $cachePuts = 0,
        public ?BatchFallbackReason $fallbackReason = null,
    ) {
    }

    public function endedAtUnixNanoseconds(): int
    {
        return $this->startedAtUnixNanoseconds + $this->durationNanoseconds;
    }
}
