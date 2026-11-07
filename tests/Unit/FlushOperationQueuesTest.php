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

namespace ModernORM\Tests\Unit;

use Doctrine\Common\Collections\ArrayCollection;
use ModernORM\Internal\UnitOfWork\FlushOperationQueues;
use ModernORM\PersistentCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use WeakReference;

final class FlushOperationQueuesTest extends TestCase
{
    public static function entityQueues(): iterable
    {
        yield 'insert' => ['addInsertion', 'removeInsertion', 'hasInsertion', 'insertions'];
        yield 'update' => ['addUpdate', 'removeUpdate', 'hasUpdate', 'updates'];
        yield 'delete' => ['addDeletion', 'removeDeletion', 'hasDeletion', 'deletions'];
    }

    #[DataProvider('entityQueues')]
    public function testEntityIdentityOrderAndSnapshotIsolation(string $add, string $remove, string $has, string $read): void
    {
        $queues = new FlushOperationQueues();
        $first = (object) ['id' => 1];
        $second = (object) ['id' => 1];
        self::assertFalse($queues->hasPrimaryOperations());
        $queues->$add($first);
        $queues->$add($second);
        $queues->$add($first);
        self::assertTrue($queues->hasPrimaryOperations());
        self::assertTrue($queues->$has(spl_object_id($first)));
        $snapshot = $queues->$read();
        self::assertSame([$first, $second], array_values($snapshot));
        $queues->$remove(spl_object_id($first));
        $queues->$add($first);
        self::assertSame([$second, $first], array_values($queues->$read()));
        self::assertSame([$first, $second], array_values($snapshot));
        $queues->$remove(spl_object_id($first));
        $queues->$remove(spl_object_id($second));
        self::assertFalse($queues->hasPrimaryOperations());
    }

    public function testExtraUpdatesMergeLatestFieldsWithoutDiscardingOtherFieldsOrEntities(): void
    {
        $queues = new FlushOperationQueues();
        $first = new stdClass();
        $second = new stdClass();
        $queues->addExtraUpdate($first, ['shared' => [null, 'old'], 'retained' => [1, 2]]);
        $queues->addExtraUpdate($second, ['other' => [3, 4]]);
        $queues->addExtraUpdate($first, ['shared' => [null, 'new'], 'added' => [5, 6]]);
        self::assertSame([
            spl_object_id($first) => [$first, ['shared' => [null, 'new'], 'added' => [5, 6], 'retained' => [1, 2]]],
            spl_object_id($second) => [$second, ['other' => [3, 4]]],
        ], $queues->extraUpdates());
        self::assertFalse($queues->hasPrimaryOperations());
        $queues->addInsertion($first);
        $queues->clearExtraUpdates();
        self::assertSame([], $queues->extraUpdates());
        self::assertTrue($queues->hasInsertion(spl_object_id($first)));
    }

    public function testDetachCancellationKeepsUnrelatedEntitiesAndExistingExtraUpdates(): void
    {
        $queues = new FlushOperationQueues();
        $entity = new stdClass();
        $other = new stdClass();
        $queues->addInsertion($entity);
        $queues->addUpdate($entity);
        $queues->addDeletion($entity);
        $queues->addUpdate($other);
        $queues->addExtraUpdate($entity, ['name' => ['old', 'new']]);
        $queues->removeEntity(spl_object_id($entity));
        self::assertSame([], $queues->insertions());
        self::assertSame([], $queues->deletions());
        self::assertSame([spl_object_id($other) => $other], $queues->updates());
        self::assertSame([$entity, ['name' => ['old', 'new']]], $queues->extraUpdates()[spl_object_id($entity)]);
    }

    public function testCollectionDeletionCancelsUpdateButRetainsPostCommitSynchronization(): void
    {
        $queues = new FlushOperationQueues();
        $element = new stdClass();
        /** @var ArrayCollection<array-key, object> $elements */
        $elements = new ArrayCollection(['named' => $element, 7 => $element]);
        $collection = new PersistentCollection(null, null, $elements);
        $queues->addCollectionUpdate($collection);
        $queues->removeCollectionElementAfterCommit($collection, 'named');
        $queues->removeCollectionElementAfterCommit($collection, 7);
        $queues->removeCollectionElementAfterCommit($collection, 'named');
        $queues->scheduleCollectionDeletion($collection);
        self::assertSame([], $queues->collectionUpdates());
        self::assertTrue($queues->hasCollectionDeletion(spl_object_id($collection)));
        self::assertSame([spl_object_id($collection) => $collection], $queues->collectionDeletions());
        self::assertSame([spl_object_id($collection) => $collection], $queues->visitedCollections());
        self::assertSame(['named' => true, 7 => true], $queues->pendingCollectionElementRemovals(spl_object_id($collection)));
        self::assertSame(['named' => $element, 7 => $element], $collection->toArray());
        self::assertTrue($queues->hasPrimaryOperations());
    }

    public function testDirectCollectionDeletionRetainsPreviouslyScheduledUpdate(): void
    {
        $queues = new FlushOperationQueues();
        $collection = new PersistentCollection(null, null, new ArrayCollection());
        $queues->addCollectionUpdate($collection);
        $queues->addCollectionDeletion($collection);
        self::assertSame([spl_object_id($collection) => $collection], $queues->collectionUpdates());
        self::assertSame([spl_object_id($collection) => $collection], $queues->collectionDeletions());
    }

    public function testCollectionSynchronizationAloneDoesNotStartWritesAndClearReleasesReferences(): void
    {
        $queues = new FlushOperationQueues();
        $collection = new PersistentCollection(null, null, new ArrayCollection());
        $entity = new stdClass();
        $entityReference = WeakReference::create($entity);
        $collectionReference = WeakReference::create($collection);
        $queues->removeCollectionElementAfterCommit($collection, 'removed');
        self::assertFalse($queues->hasPrimaryOperations());
        $queues->addInsertion($entity);
        $queues->addUpdate($entity);
        $queues->addDeletion($entity);
        $queues->addExtraUpdate($entity, ['name' => [null, 'value']]);
        $queues->addCollectionUpdate($collection);
        $queues->addCollectionDeletion($collection);
        $oid = spl_object_id($collection);
        unset($entity, $collection);
        $queues->clear();
        self::assertNull($entityReference->get());
        self::assertNull($collectionReference->get());
        self::assertFalse($queues->hasPrimaryOperations());
        self::assertSame([], $queues->extraUpdates());
        self::assertSame([], $queues->visitedCollections());
        self::assertSame([], $queues->pendingCollectionElementRemovals($oid));
        $queues->addInsertion($next = new stdClass());
        self::assertSame([spl_object_id($next) => $next], $queues->insertions());
    }
}
