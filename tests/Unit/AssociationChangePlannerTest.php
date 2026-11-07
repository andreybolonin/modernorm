<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use ModernORM\Internal\UnitOfWork\AssociationChangePlanner;
use ModernORM\Internal\UnitOfWork\AssociationMembership;
use ModernORM\Internal\UnitOfWork\OrphanRemovalReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class AssociationChangePlannerTest extends TestCase
{
    public function testReplacementUsesObjectIdentityAndRemovesEachDiscardedMemberOnce(): void
    {
        $kept = (object) ['id' => 1];
        $removed = (object) ['id' => 2];
        $detachedCopy = (object) ['id' => 2];
        self::assertSame([$removed], AssociationChangePlanner::removedElements(
            [10 => $kept, 20 => $removed, 30 => $removed],
            ['kept' => $kept, 'copy' => $detachedCopy],
        ));
        self::assertSame([], AssociationChangePlanner::removedElements([], [$kept]));
        self::assertSame([$kept], AssociationChangePlanner::removedElements([$kept], []));
    }

    public function testSchedulingKeepsCandidateOrderAndReasonsWithoutDuplicatingEntities(): void
    {
        $planner = new AssociationChangePlanner();
        $first = new stdClass();
        $second = new stdClass();
        $origin = new stdClass();
        $planner->schedule($first, OrphanRemovalReason::COLLECTION_MUTATION, $origin);
        $planner->schedule($second, OrphanRemovalReason::TO_ONE_REPLACEMENT);
        $planner->schedule($first, OrphanRemovalReason::COLLECTION_REPLACEMENT, $origin);
        self::assertTrue($planner->hasCandidates());
        self::assertSame([$first, $second], array_values($planner->candidates()));
        self::assertSame([OrphanRemovalReason::COLLECTION_MUTATION, OrphanRemovalReason::COLLECTION_REPLACEMENT], $planner->reasonsFor($first));
        self::assertSame([$origin, $origin], $planner->originsFor($first));
        self::assertSame([], $planner->originsFor($second));
        $planner->cancel($first);
        self::assertSame([$second], array_values($planner->candidates()));
        self::assertSame([], $planner->originsFor($first));
        self::assertSame([], $planner->reasonsFor($first));
        $planner->schedule($first, OrphanRemovalReason::EXPLICIT);
        self::assertSame([$second, $first], array_values($planner->candidates()));
        self::assertSame([OrphanRemovalReason::EXPLICIT], $planner->reasonsFor($first));
    }

    public function testClearReleasesCandidatesOriginsAndReasonsForTheNextFlush(): void
    {
        $planner = new AssociationChangePlanner();
        $entity = new stdClass();
        $origin = new stdClass();
        $planner->schedule($entity, OrphanRemovalReason::COLLECTION_MUTATION, $origin);
        $planner->clear();
        self::assertFalse($planner->hasCandidates());
        self::assertSame([], $planner->candidates());
        self::assertSame([], $planner->originsFor($entity));
        self::assertSame([], $planner->reasonsFor($entity));
        $planner->schedule($entity, OrphanRemovalReason::EXPLICIT);
        self::assertSame([], $planner->originsFor($entity));
    }

    public static function membershipCases(): iterable
    {
        $owner = new stdClass();
        $destination = new stdClass();
        yield 'no origin, explicit or to-one candidate' => [[], true];
        yield 'absent with no owning reference' => [[new AssociationMembership($owner, false)], true];
        yield 'removed from original owner' => [[new AssociationMembership($owner, false, $owner)], true];
        yield 'retained in current collection' => [[new AssociationMembership($owner, true, $owner)], false];
        yield 'owning side transferred' => [[new AssociationMembership($owner, false, $destination)], false];
        yield 'retained by another origin' => [[new AssociationMembership($owner, false), new AssociationMembership($destination, true)], false];
        yield 'absent from all origins' => [[new AssociationMembership($owner, false), new AssociationMembership($destination, false)], true];
    }

    /** @param list<AssociationMembership> $memberships */
    #[DataProvider('membershipCases')]
    public function testFinalMembershipDeterminesRemoval(array $memberships, bool $expected): void
    {
        self::assertSame($expected, (new AssociationChangePlanner())->isOrphan($memberships));
    }

    public function testRetainedMemberStopsFurtherObservation(): void
    {
        $observations = (static function (): \Generator {
            yield new AssociationMembership(new stdClass(), true);
            self::fail('A retained member must not cause another origin to be loaded.');
        })();
        self::assertFalse((new AssociationChangePlanner())->isOrphan($observations));
    }
}
