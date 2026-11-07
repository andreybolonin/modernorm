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

/** @internal An owning to-one FK between scheduled deletions. */
final readonly class DeleteDependency
{
    public function __construct(
        public object $source,
        public object $target,
        public ?string $onDelete = null,
    ) {
    }
}
