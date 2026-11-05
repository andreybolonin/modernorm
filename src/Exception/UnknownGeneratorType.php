<?php

declare(strict_types=1);

namespace ModernORM\Exception;

use LogicException;

final class UnknownGeneratorType extends LogicException implements ORMException
{
    public static function create(int $generatorType): self
    {
        return new self('Unknown generator type: ' . $generatorType);
    }
}
