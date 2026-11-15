<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\Common\Collections\Collection;
use ModernORM\Mapping as ORM;

#[ORM\Entity]
class FetchModeTarget
{
    #[ORM\Id, ORM\Column] public int $id;
}
#[ORM\Entity]
class RemovedFetchOneToMany
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\OneToMany(targetEntity: FetchModeTarget::class, mappedBy: 'owner', fetch: 'EXTRA_LAZY')]
    public Collection $targets;
}
#[ORM\Entity]
class RemovedFetchManyToMany
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToMany(targetEntity: FetchModeTarget::class, fetch: 'EXTRA_LAZY')]
    public Collection $targets;
}
#[ORM\Entity]
class RemovedFetchManyToOne
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToOne(targetEntity: FetchModeTarget::class, fetch: 'EXTRA_LAZY')]
    public ?FetchModeTarget $target = null;
}
#[ORM\Entity]
class RemovedFetchOneToOne
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\OneToOne(targetEntity: FetchModeTarget::class, fetch: 'EXTRA_LAZY')]
    public ?FetchModeTarget $target = null;
}
#[ORM\MappedSuperclass]
class FetchOverrideBase
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToMany(targetEntity: FetchModeTarget::class)]
    public Collection $targets;
}
#[ORM\Entity, ORM\AssociationOverrides([new ORM\AssociationOverride(name: 'targets', fetch: 'EXTRA_LAZY')])]
class RemovedFetchOverride extends FetchOverrideBase
{
}
#[ORM\Entity]
class FetchModeSource extends FetchOverrideBase
{
}
