<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\Common\Collections\Collection;
use ModernORM\Mapping as ORM;

#[ORM\Entity]
class CascadeModeTarget
{
    #[ORM\Id, ORM\Column] public int $id;
}
#[ORM\Entity]
class AllCascadeSource
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToMany(targetEntity: CascadeModeTarget::class, cascade: ['ALL'])]
    public Collection $targets;
}

#[ORM\Entity]
class RemovedCascadeOneToManyRefresh
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\OneToMany(targetEntity: CascadeModeTarget::class, mappedBy: 'owner', cascade: ['refresh'])]
    public Collection $targets;
}

#[ORM\Entity]
class RemovedCascadeOneToManyDetach
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\OneToMany(targetEntity: CascadeModeTarget::class, mappedBy: 'owner', cascade: ['detach'])]
    public Collection $targets;
}

#[ORM\Entity]
class RemovedCascadeOneToManyAllRefresh
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\OneToMany(targetEntity: CascadeModeTarget::class, mappedBy: 'owner', cascade: ['all', 'refresh'])]
    public Collection $targets;
}

#[ORM\Entity]
class RemovedCascadeManyToManyRefresh
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToMany(targetEntity: CascadeModeTarget::class, cascade: ['refresh'])]
    public Collection $targets;
}

#[ORM\Entity]
class RemovedCascadeManyToManyDetach
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToMany(targetEntity: CascadeModeTarget::class, cascade: ['detach'])]
    public Collection $targets;
}

#[ORM\Entity]
class RemovedCascadeManyToManyAllRefresh
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToMany(targetEntity: CascadeModeTarget::class, cascade: ['all', 'refresh'])]
    public Collection $targets;
}

#[ORM\Entity]
class RemovedCascadeOneToOneRefresh
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\OneToOne(targetEntity: CascadeModeTarget::class, cascade: ['refresh'])]
    public ?CascadeModeTarget $target;
}

#[ORM\Entity]
class RemovedCascadeOneToOneDetach
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\OneToOne(targetEntity: CascadeModeTarget::class, cascade: ['detach'])]
    public ?CascadeModeTarget $target;
}

#[ORM\Entity]
class RemovedCascadeOneToOneAllRefresh
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\OneToOne(targetEntity: CascadeModeTarget::class, cascade: ['all', 'refresh'])]
    public ?CascadeModeTarget $target;
}

#[ORM\Entity]
class RemovedCascadeManyToOneRefresh
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToOne(targetEntity: CascadeModeTarget::class, cascade: ['refresh'])]
    public ?CascadeModeTarget $target;
}

#[ORM\Entity]
class RemovedCascadeManyToOneDetach
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToOne(targetEntity: CascadeModeTarget::class, cascade: ['detach'])]
    public ?CascadeModeTarget $target;
}

#[ORM\Entity]
class RemovedCascadeManyToOneAllRefresh
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToOne(targetEntity: CascadeModeTarget::class, cascade: ['all', 'refresh'])]
    public ?CascadeModeTarget $target;
}
