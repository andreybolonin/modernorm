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

namespace ModernORM\Internal\UnitOfWork;

use WeakMap;

/** @internal Tracks native lazy references without retaining them. */
final class LazyReferenceTracker
{
    /** @var WeakMap<object, true> */
    private WeakMap $references;

    public function __construct()
    {
        $this->references = new WeakMap();
    }

    public function mark(object $entity): void
    {
        $this->references[$entity] = true;
    }

    public function contains(object $entity): bool
    {
        return isset($this->references[$entity]);
    }

    public function forget(object $entity): void
    {
        unset($this->references[$entity]);
    }

    public function clear(): void
    {
        $this->references = new WeakMap();
    }
}
