<?php

declare(strict_types=1);

namespace ModernORM\Behavior;

/**
 * Implement BlameableInterface and redeclare these properties with application-owned association mapping.
 * Keep their visibility, native type and default value identical to these declarations.
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
