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

use LogicException;

use function sprintf;

/**
 * Exception for cache.
 */
class CacheException extends LogicException implements ORMException
{
    public static function updateReadOnlyCollection(string $sourceEntity, string $fieldName): self
    {
        return new self(sprintf('Cannot update a readonly collection "%s#%s"', $sourceEntity, $fieldName));
    }

    public static function nonCacheableEntity(string $entityName): self
    {
        return new self(sprintf('Entity "%s" not configured as part of the second-level cache.', $entityName));
    }
}
