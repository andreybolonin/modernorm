<?php

declare(strict_types=1);

namespace ModernORM\Behavior;

interface ActorProviderInterface
{
    /** Return a user managed by the EntityManager being flushed, or null for an anonymous operation. */
    public function getActor(): ?object;
}
