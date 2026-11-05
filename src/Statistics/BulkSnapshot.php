<?php

declare(strict_types=1);

namespace ModernORM\Statistics;

final readonly class BulkSnapshot
{
    public function __construct(
        public int $count = 0,
        public int $errors = 0,
        public int $timeNanoseconds = 0,
        public int $inputRows = 0,
        public int $affectedRows = 0,
        public int $returnedRows = 0,
    ) {
    }
}
