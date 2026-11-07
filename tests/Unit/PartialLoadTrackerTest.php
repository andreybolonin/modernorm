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

use ModernORM\Internal\UnitOfWork\PartialLoadTracker;
use PHPUnit\Framework\TestCase;
use stdClass;
use WeakReference;

final class PartialLoadTrackerTest extends TestCase
{
    public function testUnknownObjectAndEmptyProxyAreDistinct(): void
    {
        $tracker = new PartialLoadTracker();
        $entity = new stdClass();
        self::assertNull($tracker->loadedFields($entity));
        $tracker->mark($entity, []);
        self::assertSame([], $tracker->loadedFields($entity));
        self::assertSame([], $tracker->loadedSnapshot($entity, ['id' => 1, 'name' => 'not-loaded']));
        $tracker->forget($entity);
        self::assertNull($tracker->loadedFields($entity));
    }

    public function testSnapshotSelectionPreservesLoadedNullAndSkipsUnloadedValuesAndAssociations(): void
    {
        $tracker = new PartialLoadTracker();
        $entity = new stdClass();
        $date = new \DateTimeImmutable('2026-01-01');
        $owner = new stdClass();
        $snapshot = ['id' => 1, 'title' => null, 'date' => $date, 'body' => 'unloaded', 'owner' => $owner];
        $tracker->mark($entity, ['id', 'title', 'date', 'missing']);
        self::assertSame(['id' => 1, 'title' => null, 'date' => $date], $tracker->loadedSnapshot($entity, $snapshot));
        self::assertSame($owner, $snapshot['owner']);
        self::assertArrayHasKey('title', $snapshot);
        self::assertSame('unloaded', $snapshot['body']);
    }

    public function testTrackingUsesObjectIdentityAndReturnsIndependentFieldLists(): void
    {
        $tracker = new PartialLoadTracker();
        $first = (object) ['id' => 1];
        $other = (object) ['id' => 1];
        $fields = ['id', 'title'];
        $tracker->mark($first, $fields);
        $fields[] = 'body';
        self::assertNull($tracker->loadedFields($other));
        $returned = $tracker->loadedFields($first);
        $returned[] = 'body';
        self::assertSame(['id', 'title'], $tracker->loadedFields($first));
        $tracker->mark($other, ['id']);
        $tracker->forget($first);
        self::assertSame(['id'], $tracker->loadedFields($other));
        self::assertNull($tracker->loadedFields($first));
    }

    public function testMarkReplacesPreviousProjectionAndClearStartsANewContext(): void
    {
        $tracker = new PartialLoadTracker();
        $first = new stdClass();
        $second = new stdClass();
        $tracker->mark($first, ['id', 'old']);
        $tracker->mark($first, ['id', 'new']);
        $tracker->mark($second, []);
        self::assertSame(['id', 'new'], $tracker->loadedFields($first));
        $tracker->clear();
        self::assertNull($tracker->loadedFields($first));
        self::assertNull($tracker->loadedFields($second));
        $tracker->mark($first, []);
        self::assertSame([], $tracker->loadedFields($first));
    }

    public function testTrackingDoesNotRetainObjects(): void
    {
        $tracker = new PartialLoadTracker();
        $entity = new stdClass();
        $reference = WeakReference::create($entity);
        $tracker->mark($entity, ['id']);
        unset($entity);
        self::assertNull($reference->get());
        $next = new stdClass();
        self::assertNull($tracker->loadedFields($next));
    }
}
