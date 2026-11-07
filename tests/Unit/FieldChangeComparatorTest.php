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

use DateTime;
use ModernORM\Internal\UnitOfWork\FieldChangeComparator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

enum TrackedState: string
{
    case READY = 'ready';
    case DONE = 'done';
}

final class FieldChangeComparatorTest extends TestCase
{
    public static function values(): iterable
    {
        yield 'unchanged null' => [null, null, false, null];
        yield 'set nullable value' => [null, 'value', false, [null, 'value']];
        yield 'clear nullable value' => ['value', null, false, ['value', null]];
        yield 'strict scalar types' => [1, '1', false, [1, '1']];
        yield 'unchanged enum backing value' => [TrackedState::READY, 'ready', true, null];
        yield 'changed enum backing value' => [TrackedState::READY, 'done', true, ['ready', 'done']];
        yield 'enum disabled' => [TrackedState::READY, 'ready', false, [TrackedState::READY, 'ready']];
        yield 'keyed enum array' => [['state' => TrackedState::READY, 7 => null], ['state' => 'ready', 7 => null], true, null];
        yield 'strict array keys' => [[0 => 'a'], [1 => 'a'], false, [[0 => 'a'], [1 => 'a']]];
    }

    #[DataProvider('values')]
    public function testComparison(mixed $original, mixed $current, bool $enum, ?array $expected): void
    {
        self::assertSame($expected, FieldChangeComparator::compare($original, $current, $enum));
    }

    public function testMutableObjectsRetainReferenceBasedTracking(): void
    {
        $date = new DateTime('2026-01-01');
        $snapshot = $date;
        $date->modify('+1 day');
        self::assertNull(FieldChangeComparator::compare($snapshot, $date));
        $replacement = clone $date;
        self::assertSame([$date, $replacement], FieldChangeComparator::compare($snapshot, $replacement));
    }

    public function testCustomValueObjectsAreNotConvertedOrComparedByContents(): void
    {
        $original = (object) ['value' => 'same'];
        $replacement = clone $original;
        self::assertNull(FieldChangeComparator::compare($original, $original));
        self::assertSame([$original, $replacement], FieldChangeComparator::compare($original, $replacement));
    }

    public function testEnumNormalizationDoesNotModifyTheSnapshot(): void
    {
        $original = ['state' => TrackedState::READY];
        self::assertSame([['state' => 'ready'], ['state' => 'done']], FieldChangeComparator::compare($original, ['state' => 'done'], true));
        self::assertSame(['state' => TrackedState::READY], $original);
    }
}
