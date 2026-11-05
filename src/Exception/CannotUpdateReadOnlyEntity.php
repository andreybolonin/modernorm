<?php

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
