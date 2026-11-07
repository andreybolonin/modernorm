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

use DateTimeInterface;
use Exception;
use Throwable;

/**
 * An OptimisticLockException is thrown when a version check on an object
 * that uses optimistic locking through a version field fails.
 */
class OptimisticLockException extends Exception implements ORMException
{
    public function __construct(
        string $msg,
        private readonly object|string|null $entity,
        Throwable|null $previous = null,
    ) {
        parent::__construct($msg, 0, $previous);
    }

    /**
     * Gets the entity that caused the exception.
     */
    public function getEntity(): object|string|null
    {
        return $this->entity;
    }

    /** @param object|class-string $entity */
    public static function lockFailed(object|string $entity): self
    {
        return new self('The optimistic lock on an entity failed.', $entity);
    }

    public static function lockFailedVersionMismatch(
        object $entity,
        int|string|DateTimeInterface $expectedLockVersion,
        int|string|DateTimeInterface $actualLockVersion,
    ): self {
        $expectedLockVersion = $expectedLockVersion instanceof DateTimeInterface ? $expectedLockVersion->getTimestamp() : $expectedLockVersion;
        $actualLockVersion   = $actualLockVersion instanceof DateTimeInterface ? $actualLockVersion->getTimestamp() : $actualLockVersion;

        return new self('The optimistic lock failed, version ' . $expectedLockVersion . ' was expected, but is actually ' . $actualLockVersion, $entity);
    }

    public static function notVersioned(string $entityName): self
    {
        return new self('Cannot obtain optimistic lock on unversioned entity ' . $entityName, null);
    }
}
