<?php

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
