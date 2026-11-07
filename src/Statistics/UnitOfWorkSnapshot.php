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

final readonly class UnitOfWorkSnapshot
{
    /** @param array<string, UnitOfWorkPhaseSnapshot> $byPhase */
    public function __construct(public array $byPhase = [])
    {
    }

    public function phase(UnitOfWorkPhase $phase): UnitOfWorkPhaseSnapshot
    {
        return $this->byPhase[$phase->value] ?? new UnitOfWorkPhaseSnapshot();
    }
}
