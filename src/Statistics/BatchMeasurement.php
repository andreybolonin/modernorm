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

/** @internal Progress for one selected batch; retained only until that call returns. */
final class BatchMeasurement
{
    public int $cacheHits = 0;
    public int $cacheMisses = 0;
    public int $cachePuts = 0;

    public function __construct(
        public readonly Measurement $measurement,
        public readonly BatchKind $kind,
        public readonly string $entityClass,
        public readonly ?string $association,
        public readonly int $configuredSize,
        public readonly int $selectedCount,
    ) {
    }
}
