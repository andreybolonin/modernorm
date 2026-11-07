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

namespace ModernORM\Cache;

/** Policy for ORM UPDATE; both policies invalidate INSERT/DELETE. Independent of Immutable. */
enum CacheWritePolicy: string
{
    case READ_ONLY = 'read_only';
    case INVALIDATE = 'invalidate';
}
