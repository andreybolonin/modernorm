<?php

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

/** @internal One scheduled entity and its lazily supplied outgoing FK dependencies. */
final readonly class EntityDeleteDependencies
{
    /** @param iterable<DeleteDependency> $dependencies */
    public function __construct(public object $entity, public iterable $dependencies)
    {
    }
}
