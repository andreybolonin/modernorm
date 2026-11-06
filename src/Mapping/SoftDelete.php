<?php

declare(strict_types=1);

namespace ModernORM\Mapping;

use Attribute;

/** Explicit logical deletion through the mapped deletedAt field. */
#[Attribute(Attribute::TARGET_CLASS)]
final class SoftDelete implements MappingAttribute
{
}
