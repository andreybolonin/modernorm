<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use ModernORM\Statistics;
use ModernORM\Statistics\BatchFallbackReason;
use ModernORM\Statistics\BatchKind;
use ModernORM\Statistics\OperationEvent;
use ModernORM\Statistics\OperationKind;
use ModernORM\Statistics\OperationObserver;
use PHPUnit\Framework\TestCase;

final class StatisticsEventRecorder implements OperationObserver
{
    public array $events = [];
    public function completed(OperationEvent $event): void { $this->events[] = $event; }
}

final class BatchStatisticsTest extends TestCase
{
    public function testDisabledCollectionDoesNotAllocateMeasurementsOrEmitEvents(): void
    {
        $observer = new StatisticsEventRecorder();
        $statistics = new Statistics(observer: $observer);
        self::assertNull($statistics->start());
        self::assertNull($statistics->startBatch(BatchKind::MANY_TO_ONE, 'Target', null, 32, 3));
        $statistics->recordBatchFallback(BatchKind::MANY_TO_ONE, BatchFallbackReason::SIZE_ONE, 'Target', null, 1);
        self::assertSame(0, $statistics->snapshot()->batch->count);
        self::assertSame(0, $statistics->snapshot()->batch->fallbackCount());
        self::assertSame([], $observer->events);
    }

    public function testSnapshotsAggregateKindsAndDoNotChangeAfterReset(): void
    {
        $statistics = new Statistics(enabled: true);
        $references = $statistics->startBatch(BatchKind::MANY_TO_ONE, 'Target', null, 8, 3);
        $references->cacheHits = 2; $references->cacheMisses = 1; $references->cachePuts = 1;
        $statistics->recordBatch($references, false);
        $collections = $statistics->startBatch(BatchKind::ONE_TO_MANY, 'Line', 'Document::lines', 8, 2);
        $statistics->recordBatch($collections, true);
        $statistics->recordBatchFallback(BatchKind::MANY_TO_MANY, BatchFallbackReason::CACHED_TARGET, 'Currency', 'Document::currencies', 8);
        $snapshot = $statistics->snapshot();
        self::assertSame(2, $snapshot->batch->count);
        self::assertSame(1, $snapshot->batch->errors);
        self::assertSame(3, $snapshot->batch->selectedReferences);
        self::assertSame(2, $snapshot->batch->selectedCollections);
        self::assertSame(2, $snapshot->batch->cacheHits);
        self::assertSame(1, $snapshot->batch->cacheMisses);
        self::assertSame(1, $snapshot->batch->cachePuts);
        self::assertSame(1, $snapshot->batch->byKind['one_to_many']->errors);
        self::assertSame(['cached_target' => 1], $snapshot->batch->byKind['many_to_many']->fallbackCounts);
        self::assertSame(1, $snapshot->batch->fallbackCount());
        self::assertGreaterThanOrEqual(0, $snapshot->batch->timeMilliseconds());
        $statistics->reset();
        self::assertSame(2, $snapshot->batch->count);
        self::assertSame(0, $statistics->snapshot()->batch->count);
        self::assertSame(0, $statistics->snapshot()->batch->fallbackCount());
        self::assertTrue($statistics->isEnabled());
        $this->expectException(\Error::class);
        $snapshot->batch->byKind['many_to_one']->cacheHits = 99;
    }

    public function testResetAndToggleDiscardOldBatchCompletionsAndEvents(): void
    {
        $observer = new StatisticsEventRecorder();
        $statistics = new Statistics(enabled: true, observer: $observer);
        $old = $statistics->startBatch(BatchKind::MANY_TO_ONE, 'Target', null, 3, 3);
        $statistics->reset();
        $statistics->recordBatch($old, false);
        $old = $statistics->startBatch(BatchKind::ONE_TO_MANY, 'Line', 'Document::lines', 3, 2);
        $statistics->disable(); $statistics->enable();
        $statistics->recordBatch($old, true);
        self::assertSame(0, $statistics->snapshot()->batch->count);
        self::assertSame([], $observer->events);
        $current = $statistics->startBatch(BatchKind::MANY_TO_MANY, 'Tag', 'Document::tags', 3, 1);
        $statistics->recordBatch($current, false);
        self::assertSame(1, $statistics->snapshot()->batch->count);
        self::assertCount(1, $observer->events);
        $statistics->disable();
        self::assertSame(1, $statistics->snapshot()->batch->count);
    }

    public function testEventsContainTimestampsSizesOutcomesAndNoEntityOrExceptionPayload(): void
    {
        $observer = new StatisticsEventRecorder();
        $statistics = new Statistics(enabled: true, observer: $observer);
        $measurement = $statistics->start(); $statistics->recordSql($measurement, true);
        $measurement = $statistics->start(); $statistics->recordFlush($measurement, false);
        $batch = $statistics->startBatch(BatchKind::MANY_TO_ONE, 'Target', null, 32, 2);
        $batch->cacheHits = 1; $batch->cacheMisses = 1; $batch->cachePuts = 1;
        $statistics->recordBatch($batch, false);
        $statistics->recordBatchFallback(BatchKind::MANY_TO_MANY, BatchFallbackReason::CACHED_TARGET, 'Currency', 'Document::currencies', 32);
        self::assertSame([OperationKind::SQL, OperationKind::FLUSH, OperationKind::BATCH, OperationKind::BATCH_FALLBACK], array_column($observer->events, 'kind'));
        self::assertTrue($observer->events[0]->failed);
        self::assertFalse($observer->events[1]->failed);
        $event = $observer->events[2];
        self::assertSame(32, $event->configuredSize);
        self::assertSame(2, $event->selectedReferences);
        self::assertSame(0, $event->selectedCollections);
        self::assertSame(1, $event->cacheHits);
        self::assertSame(1, $event->cacheMisses);
        self::assertSame(1, $event->cachePuts);
        self::assertGreaterThan(1_000_000_000_000_000_000, $event->startedAtUnixNanoseconds);
        self::assertSame($event->durationNanoseconds, $event->endedAtUnixNanoseconds() - $event->startedAtUnixNanoseconds);
        self::assertSame(0, $observer->events[3]->durationNanoseconds);
        self::assertSame(BatchFallbackReason::CACHED_TARGET, $observer->events[3]->fallbackReason);
        foreach (get_object_vars($event) as $value) {
            self::assertTrue($value === null || is_scalar($value) || $value instanceof \UnitEnum);
        }
    }

    public function testObserverFailuresAreIsolatedAndCannotRecursivelyInstrumentExport(): void
    {
        $observer = new class implements OperationObserver {
            public Statistics $statistics;
            public int $calls = 0;
            public bool $nestedMeasurementWasNull = false;
            public function completed(OperationEvent $event): void
            {
                ++$this->calls;
                $this->nestedMeasurementWasNull = $this->statistics->start() === null;
                $this->statistics->recordBatchFallback(BatchKind::MANY_TO_ONE, BatchFallbackReason::NOT_QUEUED, 'Nested', null, 1);
                throw new \RuntimeException('export unavailable');
            }
        };
        $statistics = new Statistics(enabled: true, observer: $observer); $observer->statistics = $statistics;
        $measurement = $statistics->start(); $statistics->recordSql($measurement, false);
        self::assertSame(1, $observer->calls);
        self::assertTrue($observer->nestedMeasurementWasNull);
        self::assertSame(1, $statistics->snapshot()->sqlCount);
        self::assertSame(1, $statistics->snapshot()->observerErrors);
        self::assertSame(0, $statistics->snapshot()->batch->fallbackCount());
        $statistics->reset();
        self::assertSame(0, $statistics->snapshot()->observerErrors);
    }

    public function testResetInsideFailingObserverDoesNotLeakItsErrorIntoNextJob(): void
    {
        $observer = new class implements OperationObserver {
            public Statistics $statistics;
            public function completed(OperationEvent $event): void
            {
                $this->statistics->reset();
                throw new \RuntimeException('old request exporter failed');
            }
        };
        $statistics = new Statistics(enabled: true, observer: $observer); $observer->statistics = $statistics;
        $measurement = $statistics->start(); $statistics->recordSql($measurement, false);
        self::assertSame(0, $statistics->snapshot()->sqlCount);
        self::assertSame(0, $statistics->snapshot()->observerErrors);
    }
}
