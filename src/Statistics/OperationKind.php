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

enum OperationKind: string
{
    case SQL = 'sql';
    case FLUSH = 'flush';
    case BATCH = 'batch';
    case BATCH_FALLBACK = 'batch_fallback';
    case BULK = 'bulk';
    case HYDRATION = 'hydration';
    case UNIT_OF_WORK = 'unit_of_work';
}
