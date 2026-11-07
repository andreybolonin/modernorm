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

/**
 * Exception thrown when an ORM query unexpectedly returns more than one result.
 */
class NonUniqueResultException extends UnexpectedResultException
{
    public const DEFAULT_MESSAGE = 'More than one result was found for query although one row or none was expected.';

    public function __construct(string|null $message = null)
    {
        parent::__construct($message ?? self::DEFAULT_MESSAGE);
    }
}
