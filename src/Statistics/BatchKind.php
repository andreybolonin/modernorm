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

enum BatchKind: string
{
    case MANY_TO_ONE = 'many_to_one';
    case ONE_TO_MANY = 'one_to_many';
    case MANY_TO_MANY = 'many_to_many';
}
