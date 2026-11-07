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

use ModernORM\Bulk\BulkPolicy;

final readonly class BulkDetails implements \JsonSerializable
{
    public function __construct(
        public BulkOperation $operation,
        public string $entityClass,
        public BulkPolicy $policy,
        public int $inputRows,
        public ?int $affectedRows,
        public ?int $returnedRows,
        public ?bool $databaseCommitted,
    ) {
    }

    /** @return array<string, int|string|bool|null> */
    public function jsonSerialize(): array
    {
        return ['operation' => $this->operation->value, 'entityClass' => $this->entityClass,
            'policy' => $this->policy->name, 'inputRows' => $this->inputRows,
            'affectedRows' => $this->affectedRows, 'returnedRows' => $this->returnedRows,
            'databaseCommitted' => $this->databaseCommitted];
    }
}
