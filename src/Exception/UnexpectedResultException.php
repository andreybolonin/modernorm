<?php

declare(strict_types=1);

namespace ModernORM\Exception;

use RuntimeException;

/**
 * Exception for a unexpected query result.
 */
class UnexpectedResultException extends RuntimeException implements ORMException
{
}
