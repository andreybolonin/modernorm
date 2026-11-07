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

/** Immutable aggregate values; SQL time and flush time overlap and must not be added. */
final readonly class Snapshot
{
    public function __construct(
        public int $sqlCount,
        public int $sqlTimeNanoseconds,
        public int $sqlErrors,
        public int $flushCount,
        public int $flushTimeNanoseconds,
        public int $flushErrors,
        public BatchSnapshot $batch = new BatchSnapshot(),
        public int $observerErrors = 0,
        public BulkSnapshot $bulk = new BulkSnapshot(),
        public HydrationSnapshot $hydration = new HydrationSnapshot(),
        public UnitOfWorkSnapshot $unitOfWork = new UnitOfWorkSnapshot(),
        public DmlSnapshot $dml = new DmlSnapshot(),
    ) {
    }

    public function sqlTimeMilliseconds(): float
    {
        return $this->sqlTimeNanoseconds / 1_000_000;
    }
    public function flushTimeMilliseconds(): float
    {
        return $this->flushTimeNanoseconds / 1_000_000;
    }
}
