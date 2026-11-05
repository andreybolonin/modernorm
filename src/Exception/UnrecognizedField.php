<?php

declare(strict_types=1);

namespace ModernORM\Exception;

use function sprintf;

final class UnrecognizedField extends PersisterException
{
    /** @param class-string $className */
    public static function byFullyQualifiedName(string $className, string $field): self
    {
        return new self(sprintf('Unrecognized field: %s::$%s', $className, $field));
    }
}
