<?php

declare(strict_types=1);

namespace ModernORM\Statistics;

final readonly class HydrationDetails
{
    public function __construct(
        public string $hydrator,
        public bool $streamed,
        public int $rows,
        public int $entitiesCreated,
        public bool $completed,
    ) {
    }
}
