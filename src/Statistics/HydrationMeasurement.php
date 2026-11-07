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

/** @internal Active work only; suspended generators do not retain an active collector scope. */
final class HydrationMeasurement
{
    public int $rows = 0;
    public int $entitiesCreated = 0;
    public int $activeNanoseconds = 0;
    public ?int $activeStartedAt;
    public ?self $previous = null;

    public function __construct(public readonly Measurement $measurement, public readonly string $hydrator, public readonly bool $streamed)
    {
        $this->activeStartedAt = $measurement->startedAt;
    }
}
