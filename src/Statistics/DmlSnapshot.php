<?php

declare(strict_types=1);

namespace ModernORM\Statistics;

final readonly class DmlSnapshot
{
    public function __construct(
        public DmlOperationSnapshot $insert = new DmlOperationSnapshot(),
        public DmlOperationSnapshot $update = new DmlOperationSnapshot(),
        public DmlOperationSnapshot $delete = new DmlOperationSnapshot(),
    ) {
    }
}
