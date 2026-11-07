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

/** Maps createdBy and updatedBy to an application-owned actor entity. */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Blameable implements MappingAttribute
{
    /** @param class-string $actorClass */
    public function __construct(public string $actorClass)
    {
    }
}
