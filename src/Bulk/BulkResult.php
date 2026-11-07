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

namespace ModernORM\Bulk;

final readonly class BulkResult
{
    /** @param list<array<string, mixed>> $returnedRows Raw DBAL rows, never managed entities. */
    public function __construct(
        public int $affectedRows,
        public array $returnedRows,
        /** False means the caller's transaction is still pending. */
        public bool $databaseCommitted,
    ) {
    }
}
