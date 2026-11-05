<?php

declare(strict_types=1);

namespace ModernORM\Mapping;

use Attribute;
use SortDirection;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class OrderBy implements MappingAttribute
{
    /** @param array<string, SortDirection|'ASC'|'DESC'> $value */
    public function __construct(
        public readonly array $value,
    ) {
    }
}
