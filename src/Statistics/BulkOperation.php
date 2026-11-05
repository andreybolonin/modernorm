<?php

declare(strict_types=1);

namespace ModernORM\Statistics;

enum BulkOperation: string
{
    case INSERT = 'insert';
    case UPSERT = 'upsert';
    case UPDATE = 'update';
    case DELETE = 'delete';
}
