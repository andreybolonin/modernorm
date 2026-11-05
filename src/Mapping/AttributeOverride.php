<?php

declare(strict_types=1);

namespace ModernORM\Mapping;

/** This attribute is used to override the mapping of a entity property. */
final class AttributeOverride implements MappingAttribute
{
    public function __construct(
        public string $name,
        public Column $column,
    ) {
    }
}
