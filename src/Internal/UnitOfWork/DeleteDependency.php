<?php

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
