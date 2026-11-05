<?php

declare(strict_types=1);

namespace ModernORM\Statistics;

enum BatchKind: string
{
    case MANY_TO_ONE = 'many_to_one';
    case ONE_TO_MANY = 'one_to_many';
    case MANY_TO_MANY = 'many_to_many';
}
