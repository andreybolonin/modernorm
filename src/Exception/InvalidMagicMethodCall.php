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

final class InvalidMagicMethodCall extends LogicException implements RepositoryException
{
    public static function becauseFieldNotFoundIn(
        string $entityName,
        string $fieldName,
        string $method,
    ): self {
        return new self(
            "Entity '" . $entityName . "' has no field '" . $fieldName . "'. " .
            "You can therefore not call '" . $method . "' on the entities' repository.",
        );
    }

    public static function onMissingParameter(string $methodName): self
    {
        return new self("You need to pass a parameter to '" . $methodName . "'");
    }
}
