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

/**
 * Is thrown when a transaction is required for the current operation, but there is none open.
 *
 * @link        www.doctrine-project.com
 */
class TransactionRequiredException extends LogicException implements ORMException
{
    public static function transactionRequired(): self
    {
        return new self('An open transaction is required for this operation.');
    }
}
