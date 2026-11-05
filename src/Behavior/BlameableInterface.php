<?php

declare(strict_types=1);

namespace ModernORM\Behavior;

interface BlameableInterface
{
    public function getCreatedBy(): ?object;

    public function getUpdatedBy(): ?object;

    public function setCreatedBy(?object $actor): static;

    public function setUpdatedBy(?object $actor): static;
}
