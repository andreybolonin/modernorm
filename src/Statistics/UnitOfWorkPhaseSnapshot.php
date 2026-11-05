<?php

declare(strict_types=1);

namespace ModernORM\Statistics;

final readonly class UnitOfWorkPhaseSnapshot
{
    public function __construct(
        public int $count = 0,
        public int $errors = 0,
        public int $timeNanoseconds = 0,
        public ?UnitOfWorkDetails $last = null,
    ) {
    }
}
