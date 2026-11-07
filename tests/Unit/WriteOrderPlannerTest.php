<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use ModernORM\Exception\CycleDetectedException;
use ModernORM\Internal\UnitOfWork\DeleteDependency;
use ModernORM\Internal\UnitOfWork\EntityDeleteDependencies;
use ModernORM\Internal\UnitOfWork\InsertDependency;
use ModernORM\Internal\UnitOfWork\WriteOrderPlanner;
use PHPUnit\Framework\TestCase;
use stdClass;

final class WriteOrderPlannerTest extends TestCase
{
    /**
     * @param list<object> $entities
     * @param list<DeleteDependency> $cascade
     * @param list<DeleteDependency> $dependencies
     * @return list<object>
     */
    private function orderDeletes(array $entities, array $cascade, array $dependencies): array
    {
        $groups = (static function () use ($entities, $dependencies): \Generator {
            foreach ($entities as $entity) {
                yield new EntityDeleteDependencies($entity, array_values(array_filter(
                    $dependencies,
                    static fn (DeleteDependency $dependency): bool => $dependency->source === $entity,
                )));
            }
        })();
        return (new WriteOrderPlanner())->orderDeletes($entities, $cascade, $groups);
    }

    public function testIndependentOperationsRetainSchedulingOrderAndPlannerIsReusable(): void
    {
        $planner = new WriteOrderPlanner();
        $a = new stdClass();
        $b = new stdClass();
        self::assertSame([$a, $b], $planner->orderInserts([9 => $a, 4 => $b], []));
        self::assertSame([$b, $a], $planner->orderInserts([$b, $a], []));
        self::assertSame([$a, $b], $this->orderDeletes([$a, $b], [], []));
        self::assertSame([], $this->orderDeletes([], [], []));
    }

    public function testInsertOrdersReferencedEntitiesBeforeDependents(): void
    {
        $a = new stdClass();
        $b = new stdClass();
        $c = new stdClass();
        self::assertSame([$c, $b, $a], (new WriteOrderPlanner())->orderInserts([$a, $b, $c], [
            new InsertDependency($a, $b, false), new InsertDependency($b, $c, false),
        ]));
    }

    public function testNullableInsertEdgeBreaksCycleWithoutBreakingRequiredEdge(): void
    {
        $a = new stdClass();
        $b = new stdClass();
        self::assertSame([$a, $b], (new WriteOrderPlanner())->orderInserts([$a, $b], [
            new InsertDependency($a, $b, true), new InsertDependency($b, $a, false),
        ]));
    }

    public function testRequiredInsertCycleFailsAndDoesNotPoisonNextPlan(): void
    {
        $planner = new WriteOrderPlanner();
        $a = new stdClass();
        $b = new stdClass();
        try {
            $planner->orderInserts([$a, $b], [new InsertDependency($a, $b, false), new InsertDependency($b, $a, false)]);
            self::fail('Expected required FK cycle.');
        } catch (CycleDetectedException) {
        }
        self::assertSame([$b, $a], $planner->orderInserts([$a, $b], [new InsertDependency($a, $b, false)]));
    }

    public function testAssignedIdentifierAllowsRequiredSelfReference(): void
    {
        $a = new stdClass();
        self::assertSame([$a], (new WriteOrderPlanner())->orderInserts([$a], [new InsertDependency($a, $a, false, true)]));
    }

    public function testGeneratedIdentifierDoesNotAllowRequiredSelfReference(): void
    {
        $a = new stdClass();
        $this->expectException(CycleDetectedException::class);
        (new WriteOrderPlanner())->orderInserts([$a], [new InsertDependency($a, $a, false)]);
    }

    public function testOptionalDuplicateCannotOverrideRequiredDependency(): void
    {
        $a = new stdClass();
        $b = new stdClass();
        $this->expectException(CycleDetectedException::class);
        (new WriteOrderPlanner())->orderInserts([$a, $b], [
            new InsertDependency($a, $b, false), new InsertDependency($a, $b, true), new InsertDependency($b, $a, false),
        ]);
    }

    public function testDependenciesOutsideTheScheduledSetAreIgnored(): void
    {
        $a = new stdClass();
        $outside = new stdClass();
        $planner = new WriteOrderPlanner();
        self::assertSame([$a], $planner->orderInserts([$a], [new InsertDependency($a, $outside, false), new InsertDependency($outside, $a, false)]));
        $dependencies = [new DeleteDependency($a, $outside, 'CASCADE'), new DeleteDependency($outside, $a, 'CASCADE')];
        self::assertSame([$a], $this->orderDeletes([$a], $dependencies, $dependencies));
    }

    public function testDeleteOrdersDependentsBeforeReferencedEntities(): void
    {
        $a = new stdClass();
        $b = new stdClass();
        $c = new stdClass();
        self::assertSame([$a, $b, $c], $this->orderDeletes([$c, $b, $a], [], [new DeleteDependency($a, $b), new DeleteDependency($b, $c)]));
    }

    public function testSetNullDependencyDoesNotConstrainDeleteOrder(): void
    {
        $a = new stdClass();
        $b = new stdClass();
        self::assertSame([$b, $a], $this->orderDeletes([$b, $a], [], [new DeleteDependency($a, $b, 'SeT NuLl')]));
    }

    public function testCascadeCycleIsGroupedAndExternalDependentsAreDeletedFirst(): void
    {
        $a = new stdClass();
        $b = new stdClass();
        $child = new stdClass();
        $cascade = [new DeleteDependency($a, $b, 'CASCADE'), new DeleteDependency($b, $a, 'cascade')];
        self::assertSame([$child, $b, $a], $this->orderDeletes([$a, $b, $child], $cascade, [...$cascade, new DeleteDependency($child, $a)]));
    }

    public function testDeleteGroupRejectsDependencyFromAnotherSource(): void
    {
        $a = new stdClass();
        $b = new stdClass();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('source must match');
        (new WriteOrderPlanner())->orderDeletes([$a, $b], [], [
            new EntityDeleteDependencies($a, [new DeleteDependency($b, $a)]),
        ]);
    }

    public function testNonCascadeCycleStillFails(): void
    {
        $a = new stdClass();
        $b = new stdClass();
        $dependencies = [new DeleteDependency($a, $b), new DeleteDependency($b, $a)];
        $this->expectException(CycleDetectedException::class);
        $this->orderDeletes([$a, $b], $dependencies, $dependencies);
    }
}
