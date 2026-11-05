<?php

declare(strict_types=1);

namespace ModernORM\Statistics;

final readonly class HydrationSnapshot
{
    public function __construct(
        public int $count = 0,
        public int $errors = 0,
        public int $timeNanoseconds = 0,
        public int $rows = 0,
        public int $entitiesCreated = 0,
        public int $incomplete = 0,
    ) {
    }
}
