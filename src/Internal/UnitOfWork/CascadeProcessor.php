<?php

/*
 * This file is part of the ModernORM package.
 *
 * (c) Andrey Bolonin <andreybolonin1989@gmail.com>
 * (c) Doctrine Project
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

use Closure;
use Doctrine\Common\Collections\Collection;
use ModernORM\EntityManagerInterface;
use ModernORM\Exception\ORMInvalidArgumentException;
use ModernORM\Mapping\AssociationMapping;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\PersistentCollection;
use ModernORM\UnitOfWork;

/** @internal Traverses cascade graphs; entity state transitions remain in UnitOfWork. */
final class CascadeProcessor
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UnitOfWork $unitOfWork,
    ) {
    }

    /** @param Closure(object, ClassMetadata): void $persist */
    public function persist(object $entity, Closure $persist): void
    {
        $visited = [];
        $this->visitPersist($entity, $visited, $persist);
    }

    /**
     * @param array<int, object> $visited
     * @param Closure(object, ClassMetadata): void $persist
     */
    private function visitPersist(object $entity, array &$visited, Closure $persist): void
    {
        $oid = spl_object_id($entity);
        if (isset($visited[$oid])) {
            return;
        }
        $visited[$oid] = $entity;
        $class = $this->em->getClassMetadata($entity::class);
        $persist($entity, $class);
        $this->cascadePersist($entity, $class, $visited, $persist);
    }

    /**
     * A handled soft delete stops traversal at that node. Physical deletion visits children first,
     * before removal can evict the owner from the Identity Map.
     *
     * @param Closure(object, ClassMetadata): bool $trySoftDelete
     * @param Closure(object, ClassMetadata): void $remove
     */
    public function remove(object $entity, Closure $trySoftDelete, Closure $remove): void
    {
        $visited = [];
        $this->visitRemove($entity, $visited, $trySoftDelete, $remove);
    }

    /**
     * @param array<int, object> $visited
     * @param Closure(object, ClassMetadata): bool $trySoftDelete
     * @param Closure(object, ClassMetadata): void $remove
     */
    private function visitRemove(object $entity, array &$visited, Closure $trySoftDelete, Closure $remove): void
    {
        $oid = spl_object_id($entity);
        if (isset($visited[$oid])) {
            return;
        }
        $visited[$oid] = $entity;
        $class = $this->em->getClassMetadata($entity::class);
        if ($trySoftDelete($entity, $class)) {
            return;
        }
        $this->cascadeRemove($entity, $class, $visited, $trySoftDelete, $remove);
        $remove($entity, $class);
    }

    /** @param array<int, object> $visited
     *  @param Closure(object, ClassMetadata): void $persist
     */
    private function cascadePersist(object $entity, ClassMetadata $class, array &$visited, Closure $persist): void
    {
        if ($this->unitOfWork->isUninitializedObject($entity)) {
            // nothing to do - proxy is not initialized, therefore we don't do anything with it
            return;
        }

        $associationMappings = array_filter(
            $class->associationMappings,
            static fn (AssociationMapping $assoc): bool => $assoc->isCascadePersist(),
        );

        foreach ($associationMappings as $assoc) {
            $relatedEntities = $class->propertyAccessors[$assoc->fieldName]->getValue($entity);

            switch (true) {
                case $relatedEntities instanceof PersistentCollection:
                    // Unwrap so that foreach() does not initialize
                    $relatedEntities = $relatedEntities->unwrap();
                    // break; is commented intentionally!

                    // no break
                case $relatedEntities instanceof Collection:
                case is_array($relatedEntities):
                    if ($assoc->isToMany() <= 0) {
                        throw ORMInvalidArgumentException::invalidAssociation(
                            $this->em->getClassMetadata($assoc->targetEntity),
                            $assoc,
                            $relatedEntities,
                        );
                    }

                    foreach ($relatedEntities as $relatedEntity) {
                        $this->visitPersist($relatedEntity, $visited, $persist);
                    }

                    break;

                case $relatedEntities !== null:
                    if (! $relatedEntities instanceof $assoc->targetEntity) {
                        throw ORMInvalidArgumentException::invalidAssociation(
                            $this->em->getClassMetadata($assoc->targetEntity),
                            $assoc,
                            $relatedEntities,
                        );
                    }

                    $this->visitPersist($relatedEntities, $visited, $persist);
                    break;

                default:
                    // Do nothing
            }
        }
    }

    /** @param array<int, object> $visited
     *  @param Closure(object, ClassMetadata): bool $trySoftDelete
     *  @param Closure(object, ClassMetadata): void $remove
     */
    private function cascadeRemove(object $entity, ClassMetadata $class, array &$visited, Closure $trySoftDelete, Closure $remove): void
    {
        $associationMappings = array_filter(
            $class->associationMappings,
            static fn (AssociationMapping $assoc): bool => $assoc->isCascadeRemove(),
        );

        if ($associationMappings === []) {
            return;
        }

        $this->unitOfWork->initializeObject($entity);

        $entitiesToCascade = [];

        foreach ($associationMappings as $assoc) {
            $relatedEntities = $class->propertyAccessors[$assoc->fieldName]->getValue($entity);

            switch (true) {
                case $relatedEntities instanceof Collection:
                case is_array($relatedEntities):
                    // If its a PersistentCollection initialization is intended! No unwrap!
                    foreach ($relatedEntities as $relatedEntity) {
                        $entitiesToCascade[] = $relatedEntity;
                    }

                    break;

                case $relatedEntities !== null:
                    $entitiesToCascade[] = $relatedEntities;
                    break;

                default:
                    // Do nothing
            }
        }

        foreach ($entitiesToCascade as $relatedEntity) {
            $this->visitRemove($relatedEntity, $visited, $trySoftDelete, $remove);
        }
    }
}
