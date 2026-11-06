<?php

declare(strict_types=1);

namespace ModernORM\Statistics;

/** Successful statements can later roll back; affected rows are only known driver counts. */
final readonly class DmlOperationSnapshot
{
    public function __construct(
        public int $count = 0,
        public int $errors = 0,
        public int $timeNanoseconds = 0,
        public int $affectedRows = 0,
        public int $unknownRowCounts = 0,
    ) {
    }
}
