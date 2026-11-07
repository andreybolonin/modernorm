<?php

/*
 * This file is part of the ModernORM package.
 *
 * (c) Andrey Bolonin <andreybolonin1989@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ModernORM\Mapping;

use Attribute;

/** Explicit logical deletion through the mapped deletedAt field. */
#[Attribute(Attribute::TARGET_CLASS)]
final class SoftDelete implements MappingAttribute
{
}
