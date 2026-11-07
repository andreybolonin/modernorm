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

use InvalidArgumentException;

use function sprintf;

final class NotAProxyClass extends InvalidArgumentException implements ORMException
{
    public function __construct(string $className, string $proxyNamespace)
    {
        parent::__construct(sprintf(
            'The class "%s" is not part of the proxy namespace "%s"',
            $className,
            $proxyNamespace,
        ));
    }
}
