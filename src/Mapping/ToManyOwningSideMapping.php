<?php

declare(strict_types=1);

namespace ModernORM\Mapping;

abstract class ToManyOwningSideMapping extends OwningSideMapping
{
    use ToManyAssociationMappingImplementation;
}
