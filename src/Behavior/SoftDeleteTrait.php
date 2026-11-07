<?php

/*
 * This file is part of the ModernORM package.
 *
 * (c) Andrey Bolonin <andreybolonin1989@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ModernORM\Behavior;

use DateTimeImmutable;
use ModernORM\Mapping as ORM;

/** Enable #[ORM\SoftDelete] on the entity; remove/restore are handled by the EntityManager. */
trait SoftDeleteTrait
{
    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $deletedAt = null;

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
