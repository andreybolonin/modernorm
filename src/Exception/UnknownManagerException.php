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

use OutOfBoundsException;

use function implode;
use function sprintf;

final class UnknownManagerException extends OutOfBoundsException
{
    /** @phpstan-param list<string> $knownManagers */
    public static function unknownManager(string $unknownManager, array $knownManagers = []): self
    {
        return new self(sprintf(
            'Requested unknown entity manager: %s, known managers: %s',
            $unknownManager,
            implode(', ', $knownManagers),
        ));
    }
}
