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

use InvalidArgumentException;
use ModernORM\EntityManagerInterface;
use ModernORM\Event\PrePersistEventArgs;
use ModernORM\Event\PreUpdateEventArgs;

final class BlameableListener
{
    public function __construct(private readonly ActorProviderInterface $actorProvider)
    {
    }

    public function prePersist(PrePersistEventArgs $event): void
    {
        $entity = $event->getObject();
        if (! $entity instanceof BlameableInterface) {
            return;
        }

        $actor = $this->actorProvider->getActor();
        if ($actor === null) {
            return;
        }

        $this->requireManagedActor($event->getObjectManager(), $actor);
        if ($entity->getCreatedBy() === null) {
            $entity->setCreatedBy($actor);
        }
        if ($entity->getUpdatedBy() === null) {
            $entity->setUpdatedBy($actor);
        }
    }

    public function preUpdate(PreUpdateEventArgs $event): void
    {
        $entity = $event->getObject();
        if (! $entity instanceof BlameableInterface) {
            return;
        }

        $actor = $this->actorProvider->getActor();
        if ($actor === null) {
            return;
        }

        $this->requireManagedActor($event->getObjectManager(), $actor);
        $entity->setUpdatedBy($actor);
    }

    private function requireManagedActor(EntityManagerInterface $em, object $actor): void
    {
        if (! $em->contains($actor)) {
            throw new InvalidArgumentException('The blameable actor must be managed by the EntityManager receiving the event.');
        }
    }
}
