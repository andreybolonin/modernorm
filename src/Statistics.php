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

namespace ModernORM;

use ModernORM\Statistics\Measurement;
use ModernORM\Statistics\DmlOperation;
use ModernORM\Statistics\DmlDetails;
use ModernORM\Statistics\DmlSnapshot;
use ModernORM\Statistics\DmlOperationSnapshot;
use ModernORM\Statistics\BatchKind;
use ModernORM\Statistics\BatchFallbackReason;
use ModernORM\Statistics\BatchMeasurement;
use ModernORM\Statistics\BatchSnapshot;
use ModernORM\Statistics\OperationEvent;
use ModernORM\Statistics\OperationKind;
use ModernORM\Statistics\OperationObserver;
use ModernORM\Statistics\Snapshot;
use ModernORM\Statistics\BulkDetails;
use ModernORM\Statistics\BulkSnapshot;
use ModernORM\Statistics\HydrationDetails;
use ModernORM\Statistics\HydrationMeasurement;
use ModernORM\Statistics\HydrationSnapshot;
use ModernORM\Statistics\UnitOfWorkDetails;
use ModernORM\Statistics\UnitOfWorkPhaseSnapshot;
use ModernORM\Statistics\UnitOfWorkSnapshot;

/** Aggregate completed operation attempts for one instrumented DBAL connection. */
final class Statistics
{
    private int $generation = 0;
    private bool $driverBound = false;
    private int $sqlCount = 0;
    private int $sqlTimeNanoseconds = 0;
    private int $sqlErrors = 0;
    private int $flushCount = 0;
    private int $flushTimeNanoseconds = 0;
    private int $flushErrors = 0;

    /** @var array<string, DmlOperationSnapshot> */
    private array $dml = [];
    private array $batches = [];
    private int $observerErrors = 0;
    private bool $notifying = false;
    private BulkSnapshot $bulk;
    private HydrationSnapshot $hydration;
    /** @var array<string, UnitOfWorkPhaseSnapshot> */
    private array $phases = [];
    private ?HydrationMeasurement $hydrationScope = null;

    public function __construct(private bool $enabled = false, private readonly ?OperationObserver $observer = null)
    {
        $this->bulk = new BulkSnapshot();
        $this->hydration = new HydrationSnapshot();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function enable(): void
    {
        if (! $this->enabled) {
            ++$this->generation;
            $this->enabled = true;
        }
    }

    /** Preserve totals and discard in-flight measurements; enable starts a new measurement generation. */
    public function disable(): void
    {
        if ($this->enabled) {
            ++$this->generation;
            $this->enabled = false;
            $this->hydrationScope = null;
        }
    }

    /** Reset counters without changing EntityManager state or the enabled flag. */
    public function reset(): void
    {
        ++$this->generation;
        $this->sqlCount = $this->sqlTimeNanoseconds = $this->sqlErrors = 0;
        $this->flushCount = $this->flushTimeNanoseconds = $this->flushErrors = 0;
        $this->batches = [];
        $this->dml = [];
        $this->observerErrors = 0;
        $this->bulk = new BulkSnapshot();
        $this->hydration = new HydrationSnapshot();
        $this->phases = [];
        $this->hydrationScope = null;
    }

    public function snapshot(): Snapshot
    {
        return new Snapshot(
            $this->sqlCount,
            $this->sqlTimeNanoseconds,
            $this->sqlErrors,
            $this->flushCount,
            $this->flushTimeNanoseconds,
            $this->flushErrors,
            $this->batchSnapshot(),
            $this->observerErrors,
            $this->bulk,
            $this->hydration,
            new UnitOfWorkSnapshot($this->phases),
            new DmlSnapshot($this->dml['insert'] ?? new DmlOperationSnapshot(), $this->dml['update'] ?? new DmlOperationSnapshot(), $this->dml['delete'] ?? new DmlOperationSnapshot()),
        );
    }

    /** @internal Called once when DBAL constructs the instrumented driver, not on reconnect. */
    public function bindDriver(): void
    {
        if ($this->driverBound) {
            throw new \LogicException('Use one Statistics instance and one statistics middleware per DBAL connection.');
        }
        $this->driverBound = true;
    }

    /** @internal Detect middleware added too late to an already-created DBAL connection. */
    public function isDriverBound(): bool
    {
        return $this->driverBound;
    }

    /** @internal No clock read or measurement allocation when disabled. */
    public function start(): ?Measurement
    {
        return $this->enabled && !$this->notifying
            ? new Measurement($this->generation, hrtime(true), $this->observer === null ? null : (int) (microtime(true) * 1_000_000_000))
            : null;
    }

    /** @internal Counts failed preparation or an execution attempt; never SQL text or parameters. */
    public function recordSql(Measurement $measurement, bool $failed, ?DmlOperation $operation = null, ?int $affectedRows = null): void
    {
        if (! $this->enabled || $measurement->generation !== $this->generation) {
            return;
        }
        ++$this->sqlCount;
        $this->sqlErrors += (int) $failed;
        $duration = hrtime(true) - $measurement->startedAt;
        $this->sqlTimeNanoseconds += $duration;
        $details = null;
        if ($operation !== null) {
            $old = $this->dml[$operation->value] ?? new DmlOperationSnapshot();
            $rows = $failed ? null : $affectedRows;
            $this->dml[$operation->value] = new DmlOperationSnapshot(
                $old->count + 1,
                $old->errors + (int) $failed,
                $old->timeNanoseconds + $duration,
                $old->affectedRows + ($rows ?? 0),
                $old->unknownRowCounts + (int) (!$failed && $rows === null),
            );
            if ($this->observer !== null) {
                $details = new DmlDetails($operation, $rows);
            }
        }
        $this->operationCompleted(OperationKind::SQL, $measurement, $duration, $failed, $details);
    }

    /** @internal A flush error is an exception from any flush phase, not proof of rollback. */
    public function recordFlush(Measurement $measurement, bool $failed): void
    {
        if (! $this->enabled || $measurement->generation !== $this->generation) {
            return;
        }
        ++$this->flushCount;
        $this->flushErrors += (int) $failed;
        $duration = hrtime(true) - $measurement->startedAt;
        $this->flushTimeNanoseconds += $duration;
        $this->operationCompleted(OperationKind::FLUSH, $measurement, $duration, $failed);
    }

    /** @internal Selection has finished; measure cache/SQL/hydration/callback work. */
    public function startBatch(BatchKind $kind, string $entityClass, ?string $association, int $configuredSize, int $selectedCount): ?BatchMeasurement
    {
        $measurement = $this->start();
        return $measurement === null ? null : new BatchMeasurement($measurement, $kind, $entityClass, $association, $configuredSize, $selectedCount);
    }

    /** @internal Includes unsuccessful batch attempts and their completed cache lookups/fills. */
    public function recordBatch(BatchMeasurement $batch, bool $failed): void
    {
        $measurement = $batch->measurement;
        if (!$this->enabled || $measurement->generation !== $this->generation) {
            return;
        }
        $duration = hrtime(true) - $measurement->startedAt;
        $key = $batch->kind->value;
        $this->batches[$key] ??= $this->emptyBatch();
        ++$this->batches[$key]['count'];
        $this->batches[$key]['errors'] += (int) $failed;
        $this->batches[$key]['timeNanoseconds'] += $duration;
        $sizeField = $batch->kind === BatchKind::MANY_TO_ONE ? 'selectedReferences' : 'selectedCollections';
        $this->batches[$key][$sizeField] += $batch->selectedCount;
        foreach (['cacheHits', 'cacheMisses', 'cachePuts'] as $field) {
            $this->batches[$key][$field] += $batch->$field;
        }
        if ($this->observer !== null) {
            $this->emit(new OperationEvent(
                OperationKind::BATCH,
                $measurement->startedAtUnixNanoseconds,
                $duration,
                $failed,
                $batch->kind,
                $batch->entityClass,
                $batch->association,
                $batch->configuredSize,
                $batch->kind === BatchKind::MANY_TO_ONE ? $batch->selectedCount : 0,
                $batch->kind === BatchKind::MANY_TO_ONE ? 0 : $batch->selectedCount,
                $batch->cacheHits,
                $batch->cacheMisses,
                $batch->cachePuts,
            ), $measurement->generation);
        }
    }

    /** @internal A loader-selection decision, not proof that the following single load succeeded. */
    public function recordBatchFallback(BatchKind $kind, BatchFallbackReason $reason, string $entityClass, ?string $association, int $configuredSize): void
    {
        if (!$this->enabled || $this->notifying) {
            return;
        }
        $key = $kind->value;
        $this->batches[$key] ??= $this->emptyBatch();
        $this->batches[$key]['fallbackCounts'][$reason->value] = ($this->batches[$key]['fallbackCounts'][$reason->value] ?? 0) + 1;
        if ($this->observer !== null) {
            $this->emit(new OperationEvent(
                OperationKind::BATCH_FALLBACK,
                (int) (microtime(true) * 1_000_000_000),
                0,
                false,
                $kind,
                $entityClass,
                $association,
                $configuredSize,
                fallbackReason: $reason,
            ), $this->generation);
        }
    }

    /** @internal One complete public Bulk API attempt, including validation and post-write handling. */
    public function recordBulk(Measurement $measurement, bool $failed, BulkDetails $details): void
    {
        if (!$this->accepts($measurement)) {
            return;
        }
        $duration = hrtime(true) - $measurement->startedAt;
        $old = $this->bulk;
        $this->bulk = new BulkSnapshot(
            $old->count + 1,
            $old->errors + (int) $failed,
            $old->timeNanoseconds + $duration,
            $old->inputRows + $details->inputRows,
            $old->affectedRows + ($details->affectedRows ?? 0),
            $old->returnedRows + ($details->returnedRows ?? 0)
        );
        if ($this->observer !== null) {
            $this->emit(new OperationEvent(OperationKind::BULK, $measurement->startedAtUnixNanoseconds, $duration, $failed, entityClass: $details->entityClass, bulk: $details), $measurement->generation);
        }
    }

    /** @internal Start an active scope. Suspended iterators must pause it before yielding. */
    public function startHydration(string $hydrator, bool $streamed = false): ?HydrationMeasurement
    {
        $start = $this->start();
        if ($start === null) {
            return null;
        }
        $measurement = new HydrationMeasurement($start, $hydrator, $streamed);
        $measurement->previous = $this->hydrationScope;
        $this->hydrationScope = $measurement;
        return $measurement;
    }

    /** @internal Exclude consumer code between generator yields from duration and object attribution. */
    public function pauseHydration(HydrationMeasurement $measurement): void
    {
        if ($this->hydrationScope !== $measurement) {
            return;
        }
        if ($measurement->activeStartedAt !== null) {
            $measurement->activeNanoseconds += hrtime(true) - $measurement->activeStartedAt;
        }
        $measurement->activeStartedAt = null;
        $this->hydrationScope = $measurement->previous;
        $measurement->previous = null;
    }

    /** @internal */
    public function resumeHydration(HydrationMeasurement $measurement): void
    {
        if (!$this->accepts($measurement->measurement) || $this->hydrationScope === $measurement) {
            return;
        }
        $measurement->previous = $this->hydrationScope;
        $measurement->activeStartedAt = hrtime(true);
        $this->hydrationScope = $measurement;
    }

    /** @internal Count new reconstituted entity instances, not Identity Map hits or lazy references. */
    public function entityCreatedDuringHydration(): void
    {
        if ($this->hydrationScope !== null && !$this->notifying && $this->accepts($this->hydrationScope->measurement)) {
            ++$this->hydrationScope->entitiesCreated;
        }
    }

    /** @internal Cleanup and PostLoad belong to active hydration; incomplete streams need not be errors. */
    public function recordHydration(HydrationMeasurement $measurement, bool $failed, bool $completed): void
    {
        $this->pauseHydration($measurement);
        if (!$this->accepts($measurement->measurement)) {
            return;
        }
        $old = $this->hydration;
        $this->hydration = new HydrationSnapshot(
            $old->count + 1,
            $old->errors + (int) $failed,
            $old->timeNanoseconds + $measurement->activeNanoseconds,
            $old->rows + $measurement->rows,
            $old->entitiesCreated + $measurement->entitiesCreated,
            $old->incomplete + (int) !$completed
        );
        if ($this->observer !== null) {
            $details = new HydrationDetails($measurement->hydrator, $measurement->streamed, $measurement->rows, $measurement->entitiesCreated, $completed);
            $start = $measurement->measurement;
            $this->emit(new OperationEvent(
                OperationKind::HYDRATION,
                $start->startedAtUnixNanoseconds,
                $measurement->activeNanoseconds,
                $failed,
                hydration: $details,
                finishedAtUnixNanoseconds: $measurement->streamed ? (int) (microtime(true) * 1_000_000_000) : null
            ), $start->generation);
        }
    }

    /** @internal Counts at a phase boundary are scheduled work, not SQL affected rows. */
    public function recordUnitOfWork(Measurement $measurement, bool $failed, UnitOfWorkDetails $details): void
    {
        if (!$this->accepts($measurement)) {
            return;
        }
        $duration = hrtime(true) - $measurement->startedAt;
        $old = $this->phases[$details->phase->value] ?? new UnitOfWorkPhaseSnapshot();
        $this->phases[$details->phase->value] = new UnitOfWorkPhaseSnapshot(
            $old->count + 1,
            $old->errors + (int) $failed,
            $old->timeNanoseconds + $duration,
            $details
        );
        if ($this->observer !== null) {
            $this->emit(new OperationEvent(
                OperationKind::UNIT_OF_WORK,
                $measurement->startedAtUnixNanoseconds,
                $duration,
                $failed,
                unitOfWork: $details
            ), $measurement->generation);
        }
    }

    private function accepts(Measurement $measurement): bool
    {
        return $this->enabled && $measurement->generation === $this->generation;
    }

    private function emptyBatch(): array
    {
        return ['count' => 0, 'errors' => 0, 'timeNanoseconds' => 0, 'selectedReferences' => 0,
            'selectedCollections' => 0, 'cacheHits' => 0, 'cacheMisses' => 0, 'cachePuts' => 0, 'fallbackCounts' => []];
    }

    private function batchSnapshot(): BatchSnapshot
    {
        $totals = $this->emptyBatch();
        $byKind = [];
        foreach (BatchKind::cases() as $kind) {
            $values = $this->batches[$kind->value] ?? $this->emptyBatch();
            $byKind[$kind->value] = new BatchSnapshot(...$values);
            foreach ($values as $field => $value) {
                if ($field === 'fallbackCounts') {
                    foreach ($value as $reason => $count) {
                        $totals[$field][$reason] = ($totals[$field][$reason] ?? 0) + $count;
                    }
                } else {
                    $totals[$field] += $value;
                }
            }
        }
        return new BatchSnapshot(...$totals, byKind: $byKind);
    }

    private function operationCompleted(OperationKind $kind, Measurement $measurement, int $duration, bool $failed, ?DmlDetails $dml = null): void
    {
        if ($this->observer !== null) {
            $this->emit(new OperationEvent($kind, $measurement->startedAtUnixNanoseconds, $duration, $failed, dml: $dml), $measurement->generation);
        }
    }

    private function emit(OperationEvent $event, int $generation): void
    {
        if ($this->notifying) {
            return;
        }
        $this->notifying = true;
        try {
            $this->observer->completed($event);
        } catch (\Throwable) {
            if ($this->enabled && $generation === $this->generation) {
                ++$this->observerErrors;
            }
        } finally {
            $this->notifying = false;
        }
    }
}
