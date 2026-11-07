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

namespace ModernORM\Bulk;

/** Policy for the local persistence context; L2 invalidation is independent. */
enum BulkPolicy
{
    case BYPASS;
    case CLEAR;
}
