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

enum UnitOfWorkPhase: string
{
    case CHANGE_SETS = 'change_sets';
    case WRITES = 'writes';
    case COMMIT = 'commit';
}
