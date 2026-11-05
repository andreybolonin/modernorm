<?php

declare(strict_types=1);

namespace ModernORM\Mapping;

use Attribute;

/** Skips entity field/FK updates; inserts, deletes, and association operations remain available. */
#[Attribute(Attribute::TARGET_CLASS)]
final class Immutable implements MappingAttribute
{
}
