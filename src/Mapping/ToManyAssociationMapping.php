<?php

declare(strict_types=1);

namespace ModernORM\Mapping;

use SortDirection;

interface ToManyAssociationMapping
{
    public function assertValidBatchSize(): void;

    /** @phpstan-assert-if-true string $this->indexBy() */
    public function isIndexed(): bool;

    public function indexBy(): string;

    /** @return array<string, SortDirection|'asc'|'desc'> */
    public function orderBy(): array;
}
