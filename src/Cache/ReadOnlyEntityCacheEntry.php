<?php

/*
 * This file is part of the ModernORM package.
 *
 * (c) Andrey Bolonin <andreybolonin1989@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ModernORM\Cache;

/** @internal Raw database scalar values: no managed objects or mutable PHP field instances. */
final class ReadOnlyEntityCacheEntry extends EntityCacheEntry
{
    public function __construct(string $class, array $data, public readonly string $mappingSignature)
    {
        parent::__construct($class, $data);
    }
}
