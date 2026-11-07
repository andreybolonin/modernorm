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

class PessimisticLockException extends RuntimeException implements ORMException
{
    public static function lockFailed(): self
    {
        return new self('The pessimistic lock failed.');
    }
}
