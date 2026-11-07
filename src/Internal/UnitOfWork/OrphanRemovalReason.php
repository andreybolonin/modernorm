<?php

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

/** @internal Reasons describe scheduling, not an unconditional permission to delete. */
enum OrphanRemovalReason: string
{
    case EXPLICIT = 'explicit';
    case COLLECTION_MUTATION = 'collection_mutation';
    case COLLECTION_REPLACEMENT = 'collection_replacement';
    case TO_ONE_REPLACEMENT = 'to_one_replacement';
}
