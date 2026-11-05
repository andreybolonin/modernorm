<?php

declare(strict_types=1);

namespace ModernORM\Behavior;

/**
 * Implement BlameableInterface and use #[Mapping\Blameable(actorClass: User::class)] on the entity,
 * or redeclare these properties with application-owned association mapping.
 */
trait BlameableTrait
{
    protected ?object $createdBy = null;
    protected ?object $updatedBy = null;

    public function getCreatedBy(): ?object
    {
        return $this->createdBy;
    }

    public function getUpdatedBy(): ?object
    {
        return $this->updatedBy;
    }

    public function setCreatedBy(?object $actor): static
    {
        $this->createdBy = $actor;

        return $this;
    }

    public function setUpdatedBy(?object $actor): static
    {
        $this->updatedBy = $actor;

        return $this;
    }
}
