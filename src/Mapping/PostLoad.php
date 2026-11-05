<?php

declare(strict_types=1);

namespace ModernORM\Mapping;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class PostLoad implements MappingAttribute
{
}
