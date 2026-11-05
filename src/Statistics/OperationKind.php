<?php

declare(strict_types=1);

namespace ModernORM\Statistics;

enum OperationKind: string
{
    case SQL = 'sql';
    case FLUSH = 'flush';
    case BATCH = 'batch';
    case BATCH_FALLBACK = 'batch_fallback';
}
