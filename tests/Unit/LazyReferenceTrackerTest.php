<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use ModernORM\Internal\UnitOfWork\LazyReferenceTracker;
use PHPUnit\Framework\TestCase;
use stdClass;
use WeakReference;

final class LazyReferenceTrackerTest extends TestCase
{
    public function testTrackingUsesObjectIdentityAndCanBeForgotten(): void
    {
        $tracker = new LazyReferenceTracker();
        $first = new stdClass();
        $other = new stdClass();
        self::assertFalse($tracker->contains($first));
        $tracker->mark($first);
        self::assertTrue($tracker->contains($first));
        self::assertFalse($tracker->contains($other));
        $tracker->forget($first);
        self::assertFalse($tracker->contains($first));
    }
    public function testClearStartsANewContext(): void
    {
        $tracker = new LazyReferenceTracker();
        $entity = new stdClass();
        $tracker->mark($entity);
        $tracker->clear();
        self::assertFalse($tracker->contains($entity));
        $tracker->mark($entity);
        self::assertTrue($tracker->contains($entity));
    }
    public function testTrackingDoesNotRetainObjects(): void
    {
        $tracker = new LazyReferenceTracker();
        $entity = new stdClass();
        $reference = WeakReference::create($entity);
        $tracker->mark($entity);
        unset($entity);
        self::assertNull($reference->get());
    }
}
