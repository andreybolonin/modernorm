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

use RuntimeException;
use Throwable;

/**
 * Tools related Exceptions.
 */
class ToolsException extends RuntimeException implements ORMException
{
    public static function schemaToolFailure(string $sql, Throwable $e): self
    {
        return new self(
            "Schema-Tool failed with Error '" . $e->getMessage() . "' while executing DDL: " . $sql,
            0,
            $e,
        );
    }
}
