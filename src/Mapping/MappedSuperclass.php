<?php

declare(strict_types=1);

namespace ModernORM\Mapping;

use Attribute;
use ModernORM\EntityRepository;

#[Attribute(Attribute::TARGET_CLASS)]
final class MappedSuperclass implements MappingAttribute
{
    /** @param class-string<EntityRepository>|null $repositoryClass */
    public function __construct(
        public readonly string|null $repositoryClass = null,
    ) {
    }
}
