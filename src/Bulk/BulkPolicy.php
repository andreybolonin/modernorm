<?php

declare(strict_types=1);

namespace ModernORM\Bulk;

/** Policy for the local persistence context; L2 invalidation is independent. */
enum BulkPolicy
{
    case BYPASS;
    case CLEAR;
}
