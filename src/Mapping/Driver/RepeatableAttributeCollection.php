<?php

declare(strict_types=1);

namespace ModernORM\Mapping\Driver;

use ArrayObject;
use ModernORM\Mapping\MappingAttribute;

/**
 * @template-extends ArrayObject<int, T>
 * @template T of MappingAttribute
 */
final class RepeatableAttributeCollection extends ArrayObject
{
}
