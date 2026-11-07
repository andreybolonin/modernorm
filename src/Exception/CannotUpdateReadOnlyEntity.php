<?php

/*
 * This file is part of the ModernORM package.
 *
 * (c) Andrey Bolonin <andreybolonin1989@gmail.com>
 * (c) Doctrine Project
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ModernORM\Exception;

use function sprintf;

class CannotUpdateReadOnlyEntity extends CacheException
{
    public static function fromEntity(string $entityName): self
    {
        return new self(sprintf('Cannot update a readonly entity "%s"', $entityName));
    }
}
