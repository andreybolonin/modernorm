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
 * Exception thrown when an ORM query unexpectedly does not return any results.
 */
class NoResultException extends UnexpectedResultException
{
    public function __construct()
    {
        parent::__construct('No result was found for query although at least one row was expected.');
    }
}
