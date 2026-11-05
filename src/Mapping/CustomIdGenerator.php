<?php

declare(strict_types=1);

namespace ModernORM\Mapping;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class CustomIdGenerator implements MappingAttribute
{
    public function __construct(
        public readonly string|null $class = null,
    ) {
    }
}
