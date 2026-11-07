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

namespace ModernORM\Statistics;

enum BulkOperation: string
{
    case INSERT = 'insert';
    case UPSERT = 'upsert';
    case UPDATE = 'update';
    case DELETE = 'delete';
    case SOFT_DELETE = 'soft_delete';
    case RESTORE = 'restore';
}
