<?php

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

/** @internal An owning to-one FK between scheduled inserts. */
final readonly class InsertDependency
{
    public function __construct(
        public object $source,
        public object $target,
        public bool $nullable,
        public bool $assignedIdentifier = false,
    ) {
    }
}
