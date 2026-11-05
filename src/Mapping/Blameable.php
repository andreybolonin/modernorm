<?php

declare(strict_types=1);

namespace ModernORM\Mapping;

use Attribute;

/** Maps createdBy and updatedBy to an application-owned actor entity. */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Blameable implements MappingAttribute
{
    /** @param class-string $actorClass */
    public function __construct(public string $actorClass)
    {
    }
}
