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

namespace ModernORM\Statistics;

/** Totals for selected batch attempts; timing overlaps SQL/flush and includes hydration/PostLoad. */
final readonly class BatchSnapshot
{
    /** @param array<string, int> $fallbackCounts @param array<string, BatchSnapshot> $byKind */
    public function __construct(
        public int $count = 0,
        public int $errors = 0,
        public int $timeNanoseconds = 0,
        public int $selectedReferences = 0,
        public int $selectedCollections = 0,
        public int $cacheHits = 0,
        public int $cacheMisses = 0,
        public int $cachePuts = 0,
        public array $fallbackCounts = [],
        public array $byKind = [],
    ) {
    }

    public function timeMilliseconds(): float
    {
        return $this->timeNanoseconds / 1_000_000;
    }
    public function fallbackCount(): int
    {
        return array_sum($this->fallbackCounts);
    }
}
