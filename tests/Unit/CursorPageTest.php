<?php

/* Page scenarios adapted from Doctrine ORM. Copyright (c) Doctrine Project.
 * MIT license: licenses/Doctrine-ORM-MIT.txt. */

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use ModernORM\Tools\Pagination\Cursor;
use ModernORM\Tools\Pagination\CursorPage;
use PHPUnit\Framework\TestCase;

final class CursorPageTest extends TestCase
{
    public function testNavigationIterationAndMemoizedCountAreIndependent(): void
    {
        $calls = 0;
        $items = [(object) ['id' => 2], (object) ['id' => 3]];
        $page = new CursorPage($items, true, new Cursor(['id' => 1]), static function () use (&$calls): int {
            ++$calls;
            return 10;
        }, static fn (object $item, bool $next) => new Cursor(['id' => $item->id], $next));
        self::assertSame($items, iterator_to_array($page));
        self::assertCount(2, $page);
        self::assertTrue($page->hasNextPage());
        self::assertTrue($page->hasPreviousPage());
        self::assertSame(['id' => 3], Cursor::fromEncodedString($page->getNextCursorAsString())->getParameters());
        self::assertSame(['id' => 2], Cursor::fromEncodedString($page->getPreviousCursorAsString())->getParameters());
        self::assertTrue($page->getPreviousCursor()->isPrevious());
        self::assertSame(0, $calls);
        self::assertSame(10, $page->getTotalCount());
        self::assertSame(10, $page->getTotalCount());
        self::assertSame(1, $calls);
        self::assertSame($items[0], $page->getItemsWithCursors()[0]->getValue());
        self::assertSame(['id' => 2], $page->getItemsWithCursors()[0]->getCursor()->getParameters());
    }

    public function testEmptyPageDoesNotAdvertiseUnbuildableCursors(): void
    {
        $page = new CursorPage([], false, new Cursor(['id' => 1]), static fn () => 0, static fn () => new Cursor(['id' => 1]));
        self::assertFalse($page->hasNextPage());
        self::assertFalse($page->hasPreviousPage());
        self::assertFalse($page->hasToPaginate());
        self::assertSame([], $page->getItemsWithCursors());
        $this->expectException(\LogicException::class);
        $page->getNextCursor();
    }

    public function testFirstPageCannotBuildAPreviousCursor(): void
    {
        $page = new CursorPage([(object) ['id' => 1]], false, null, static fn () => 1, static fn () => new Cursor(['id' => 1]));
        $this->expectException(\LogicException::class);
        $page->getPreviousCursor();
    }
}
