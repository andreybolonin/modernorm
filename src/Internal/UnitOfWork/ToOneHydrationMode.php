<?php

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

/** @internal A loading decision; execution and state changes belong to UnitOfWork. */
enum ToOneHydrationMode
{
    case NULL_REFERENCE;
    case SOFT_DELETE_LOOKUP;
    case MANAGED;
    case MANAGED_DEFERRED;
    case REFERENCE;
    case REFERENCE_DEFERRED;
    case EAGER_LOOKUP;
}
