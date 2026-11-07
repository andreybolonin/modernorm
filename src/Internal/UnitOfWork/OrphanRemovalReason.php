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

namespace ModernORM\Internal\UnitOfWork;

/** @internal Reasons describe scheduling, not an unconditional permission to delete. */
enum OrphanRemovalReason: string
{
    case EXPLICIT = 'explicit';
    case COLLECTION_MUTATION = 'collection_mutation';
    case COLLECTION_REPLACEMENT = 'collection_replacement';
    case TO_ONE_REPLACEMENT = 'to_one_replacement';
}
