<?php

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

/** @internal Observed facts about one candidate; reading ORM state belongs to the caller. */
final readonly class AssociationMembership
{
    public function __construct(
        public object $originalOwner,
        public bool $containsCandidate,
        public ?object $owningSideOwner = null,
    ) {
    }
}
