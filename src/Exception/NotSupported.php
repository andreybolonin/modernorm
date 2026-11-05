<?php

declare(strict_types=1);

namespace ModernORM\Exception;

use LogicException;

final class NotSupported extends LogicException implements SchemaToolException
{
    public static function create(): self
    {
        return new self('This behaviour is (currently) not supported by Doctrine 2');
    }
}
