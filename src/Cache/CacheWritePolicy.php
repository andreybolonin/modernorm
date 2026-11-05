<?php

declare(strict_types=1);

namespace ModernORM\Cache;

/** Policy for ORM UPDATE; both policies invalidate INSERT/DELETE. Independent of Immutable. */
enum CacheWritePolicy: string
{
    case READ_ONLY = 'read_only';
    case INVALIDATE = 'invalidate';
}
