<?php

declare(strict_types=1);

namespace ModernORM\Mapping;

abstract class ToManyInverseSideMapping extends InverseSideMapping implements ToManyAssociationMapping
{
    use ToManyAssociationMappingImplementation;
}
