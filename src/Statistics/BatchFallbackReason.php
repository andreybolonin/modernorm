<?php

declare(strict_types=1);

namespace ModernORM\Statistics;

enum BatchFallbackReason: string
{
    case SIZE_ONE = 'size_one';
    case PARTIAL_REFERENCE = 'partial_reference';
    case CACHED_TARGET = 'cached_target';
    case REENTRANT = 'reentrant';
    case NOT_QUEUED = 'not_queued';
    case UNMANAGED = 'unmanaged';
    case MANAGER_CLOSED = 'manager_closed';
    case PENDING_DELETE = 'pending_delete';
    case REPLACED_COLLECTION = 'replaced_collection';
    case UNSUPPORTED_JOIN = 'unsupported_join';
    case ALREADY_INITIALIZED = 'already_initialized';
}
