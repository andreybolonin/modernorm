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
use function var_export;

final class InvalidCustomGenerator extends LogicException implements ORMException
{
    public static function onClassNotConfigured(): self
    {
        return new self('Cannot instantiate custom generator, no class has been defined');
    }

    /** @param mixed[] $definition */
    public static function onMissingClass(array $definition): self
    {
        return new self(sprintf(
            'Cannot instantiate custom generator : %s',
            var_export($definition, true),
        ));
    }
}
