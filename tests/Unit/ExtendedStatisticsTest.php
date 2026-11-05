<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use ModernORM\Bulk\BulkPolicy;
use ModernORM\Statistics;
use ModernORM\Statistics\BulkDetails;
use ModernORM\Statistics\BulkOperation;
use ModernORM\Statistics\OperationEvent;
use ModernORM\Statistics\OperationObserver;
use ModernORM\Statistics\UnitOfWorkDetails;
use ModernORM\Statistics\UnitOfWorkPhase;
use PHPUnit\Framework\TestCase;

final class ExtendedStatisticsTest extends TestCase
{
    public function testDisabledInstrumentationDoesNotStartHydrationOrOperationTimers(): void
    {
        $statistics = new Statistics();
        self::assertNull($statistics->start());
        self::assertNull($statistics->startHydration('test'));
        self::assertSame(0, $statistics->snapshot()->hydration->timeNanoseconds);
        self::assertSame(0, $statistics->snapshot()->bulk->count);
        self::assertSame(0, $statistics->snapshot()->unitOfWork->phase(UnitOfWorkPhase::COMMIT)->count);
    }

    public function testResetDiscardsAllInFlightKindsAndCannotClobberNewHydrationScope(): void
    {
        $statistics = new Statistics(true);
        $old = $statistics->start();
        $oldHydration = $statistics->startHydration('old');
        $statistics->entityCreatedDuringHydration();
        $statistics->reset();
        $fresh = $statistics->startHydration('fresh');
        $statistics->resumeHydration($oldHydration);
        $statistics->recordHydration($oldHydration, false, true);
        $statistics->recordBulk($old, false, new BulkDetails(BulkOperation::INSERT, 'Record', BulkPolicy::BYPASS, 1, 1, 0, true));
        $statistics->recordUnitOfWork($old, false, new UnitOfWorkDetails(UnitOfWorkPhase::WRITES, 1, 1, 0, 0, 0, 0, 0));
        $statistics->entityCreatedDuringHydration();
        $statistics->recordHydration($fresh, false, true);
        $snapshot = $statistics->snapshot();
        self::assertSame(0, $snapshot->bulk->count);
        self::assertSame(0, $snapshot->unitOfWork->phase(UnitOfWorkPhase::WRITES)->count);
        self::assertSame(1, $snapshot->hydration->count);
        self::assertSame(1, $snapshot->hydration->entitiesCreated);
    }

    public function testDisableAndReenableDiscardsOldExtendedMeasurements(): void
    {
        $statistics = new Statistics(true);
        $bulk = $statistics->start();
        $hydration = $statistics->startHydration('old');
        $statistics->disable();
        $statistics->enable();
        $statistics->recordBulk($bulk, true, new BulkDetails(BulkOperation::DELETE, 'Record', BulkPolicy::CLEAR, 0, null, null, null));
        $statistics->recordHydration($hydration, true, false);
        self::assertSame(0, $statistics->snapshot()->bulk->errors);
        self::assertSame(0, $statistics->snapshot()->hydration->errors);
    }

    public function testNestedAndPausedScopesAttributeEachEntityOnlyOnce(): void
    {
        $statistics = new Statistics(true);
        $outer = $statistics->startHydration('outer', true);
        $statistics->entityCreatedDuringHydration();
        $statistics->pauseHydration($outer);
        $statistics->entityCreatedDuringHydration(); // Consumer work has no active scope.
        $inner = $statistics->startHydration('inner');
        $statistics->entityCreatedDuringHydration();
        $statistics->recordHydration($inner, false, true);
        $statistics->resumeHydration($outer);
        $statistics->entityCreatedDuringHydration();
        $statistics->recordHydration($outer, false, false);
        self::assertSame(2, $outer->entitiesCreated);
        self::assertSame(1, $inner->entitiesCreated);
        self::assertSame(3, $statistics->snapshot()->hydration->entitiesCreated);
        self::assertSame(1, $statistics->snapshot()->hydration->incomplete);
        $before = $statistics->snapshot();
        $statistics->reset();
        self::assertSame(2, $before->hydration->count);
        self::assertSame(0, $statistics->snapshot()->hydration->count);
        self::assertEquals($before, unserialize(serialize($before)));
    }

    public function testThrowingReentrantObserverCannotBreakExtendedOperations(): void
    {
        $observer = new class () implements OperationObserver {
            public Statistics $statistics;
            public bool $reentered = false;
            public function completed(OperationEvent $event): void
            {
                $this->reentered = $this->reentered || $this->statistics->startHydration('observer') !== null;
                $this->statistics->entityCreatedDuringHydration();
                throw new \RuntimeException('Export failed');
            }
        };
        $statistics = new Statistics(true, $observer);
        $observer->statistics = $statistics;
        $statistics->recordBulk($statistics->start(), false, new BulkDetails(BulkOperation::INSERT, 'Record', BulkPolicy::CLEAR, 2, 2, 0, true));
        $hydration = $statistics->startHydration('test');
        $statistics->entityCreatedDuringHydration();
        $statistics->recordHydration($hydration, false, true);
        $statistics->recordUnitOfWork($statistics->start(), false, new UnitOfWorkDetails(UnitOfWorkPhase::COMMIT, 1, 0, 0, 0, 0, 0, 0));
        self::assertSame(3, $statistics->snapshot()->observerErrors);
        self::assertFalse($observer->reentered);
        self::assertSame(0, $statistics->snapshot()->bulk->errors);
        self::assertSame(1, $statistics->snapshot()->hydration->entitiesCreated);
    }
}
