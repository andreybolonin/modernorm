<?php

declare(strict_types=1);

namespace ModernORM\Statistics;

enum UnitOfWorkPhase: string
{
    case CHANGE_SETS = 'change_sets';
    case WRITES = 'writes';
    case COMMIT = 'commit';
}
