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
        public ?BulkDetails $bulk = null,
        public ?HydrationDetails $hydration = null,
        public ?UnitOfWorkDetails $unitOfWork = null,
        public ?int $finishedAtUnixNanoseconds = null,
        public ?DmlDetails $dml = null,
    ) {
    }

    public function endedAtUnixNanoseconds(): int
    {
        return $this->finishedAtUnixNanoseconds ?? $this->startedAtUnixNanoseconds + $this->durationNanoseconds;
    }
}
