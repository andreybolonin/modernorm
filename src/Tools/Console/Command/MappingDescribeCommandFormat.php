<?php

declare(strict_types=1);

namespace ModernORM\Tools\Console\Command;

enum MappingDescribeCommandFormat: string
{
    case TEXT = 'text';
    case JSON = 'json';
}
