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

/** @internal A monotonic timestamp tied to a collector generation. */
final readonly class Measurement
{
    public function __construct(public int $generation, public int $startedAt, public ?int $startedAtUnixNanoseconds = null)
    {
    }
}
