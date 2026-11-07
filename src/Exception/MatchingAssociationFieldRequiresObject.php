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

use function sprintf;

final class MatchingAssociationFieldRequiresObject extends PersisterException
{
    public static function fromClassAndAssociation(string $class, string $associationName): self
    {
        return new self(sprintf(
            'Cannot match on %s::%s with a non-object value. Matching objects by id is ' .
            'not compatible with matching on an in-memory collection, which compares objects by reference.',
            $class,
            $associationName,
        ));
    }
}
