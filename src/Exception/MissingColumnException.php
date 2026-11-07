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

use function sprintf;

final class MissingColumnException extends LogicException implements ORMException
{
    public static function fromColumnSourceAndTarget(string $column, string $source, string $target): self
    {
        return new self(sprintf(
            'Column name "%s" referenced for relation from %s towards %s does not exist.',
            $column,
            $source,
            $target,
        ));
    }
}
