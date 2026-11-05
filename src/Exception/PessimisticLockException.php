<?php

declare(strict_types=1);

namespace ModernORM\Exception;

use RuntimeException;

class PessimisticLockException extends RuntimeException implements ORMException
{
    public static function lockFailed(): self
    {
        return new self('The pessimistic lock failed.');
    }
}
