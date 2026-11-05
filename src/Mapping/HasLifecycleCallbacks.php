<?php

declare(strict_types=1);

namespace ModernORM\Mapping;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class HasLifecycleCallbacks implements MappingAttribute
{
}
