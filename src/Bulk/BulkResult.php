<?php

declare(strict_types=1);

namespace ModernORM\Bulk;

final readonly class BulkResult
{
    /** @param list<array<string, mixed>> $returnedRows Raw DBAL rows, never managed entities. */
    public function __construct(
        public int $affectedRows,
        public array $returnedRows,
        /** False means the caller's transaction is still pending. */
        public bool $databaseCommitted,
    ) {}
}
