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

use ModernORM\EntityManagerInterface;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\ToManyInverseSideMapping;
use ModernORM\PersistentCollection;
use ModernORM\UnitOfWork;
use ModernORM\Utility\IdentifierFlattener;

/** @internal Owns deferred eager-loading queues and drains them in batches. */
final class EagerLoadCoordinator
{
    /** @var array<class-string, array<string, mixed>> */
    private array $eagerLoadingEntities = [];

    /** @var array<string, array{items: array<string, PersistentCollection>, mapping: ToManyInverseSideMapping}> */
    private array $eagerLoadingCollections = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UnitOfWork $unitOfWork,
        private readonly IdentifierFlattener $identifierFlattener,
    ) {
    }

    /** @param class-string $entityName */
    public function scheduleEntity(string $entityName, string $idHash, mixed $id): void
    {
        $this->eagerLoadingEntities[$entityName][$idHash] = $id;
    }

    /** @param class-string $entityName */
    public function forgetEntity(string $entityName, string $idHash): void
    {
        unset($this->eagerLoadingEntities[$entityName][$idHash]);

        if (isset($this->eagerLoadingEntities[$entityName]) && ! $this->eagerLoadingEntities[$entityName]) {
            unset($this->eagerLoadingEntities[$entityName]);
        }
    }

    public function clear(): void
    {
        $this->eagerLoadingEntities = [];
        $this->eagerLoadingCollections = [];
    }

    public function trigger(): void
    {
        if (! $this->eagerLoadingEntities && ! $this->eagerLoadingCollections) {
            return;
        }

        // avoid infinite recursion
        $eagerLoadingEntities       = $this->eagerLoadingEntities;
        $this->eagerLoadingEntities = [];

        foreach ($eagerLoadingEntities as $entityName => $ids) {
            if (! $ids) {
                continue;
            }

            $class   = $this->em->getClassMetadata($entityName);
            $batches = array_chunk($ids, $this->em->getConfiguration()->getEagerFetchBatchSize());

            foreach ($batches as $batchedIds) {
                $this->unitOfWork->getEntityPersister($entityName)->loadAll(
                    array_combine($class->identifier, [$batchedIds]),
                );
            }
        }

        $eagerLoadingCollections       = $this->eagerLoadingCollections; // avoid recursion
        $this->eagerLoadingCollections = [];

        foreach ($eagerLoadingCollections as $group) {
            $this->eagerLoadCollections($group['items'], $group['mapping']);
        }
    }

    /** @param PersistentCollection[] $collections */
    private function eagerLoadCollections(array $collections, ToManyInverseSideMapping $mapping): void
    {
        $targetEntity = $mapping->targetEntity;
        $class        = $this->em->getClassMetadata($mapping->sourceEntity);
        $mappedBy     = $mapping->mappedBy;

        $batches = array_chunk($collections, $this->em->getConfiguration()->getEagerFetchBatchSize(), true);

        foreach ($batches as $collectionBatch) {
            $entities = [];

            foreach ($collectionBatch as $collection) {
                $entities[] = $collection->getOwner();
            }

            $found = $this->unitOfWork->getEntityPersister($targetEntity)->loadAll([$mappedBy => $entities], $mapping->orderBy);

            $targetClass    = $this->em->getClassMetadata($targetEntity);
            $targetProperty = $targetClass->getPropertyAccessor($mappedBy);
            assert($targetProperty !== null);

            foreach ($found as $targetValue) {
                $sourceEntity = $targetProperty->getValue($targetValue);

                if ($sourceEntity === null && isset($targetClass->associationMappings[$mappedBy]->joinColumns)) {
                    // case where the hydration $targetValue itself has not yet fully completed, for example
                    // in case a bi-directional association is being hydrated and deferring eager loading is
                    // not possible due to subclassing.
                    $data = $this->unitOfWork->getOriginalEntityData($targetValue);
                    $id   = [];
                    foreach ($targetClass->associationMappings[$mappedBy]->joinColumns as $joinColumn) {
                        $id[] = $data[$joinColumn->name];
                    }
                } else {
                    $id = $this->identifierFlattener->flattenIdentifier($class, $class->getIdentifierValues($sourceEntity));
                }

                $idHash = implode(' ', $id);

                if ($mapping->indexBy !== null) {
                    $indexByProperty = $targetClass->getPropertyAccessor($mapping->indexBy);
                    assert($indexByProperty !== null);
                    $collectionBatch[$idHash]->hydrateSet($indexByProperty->getValue($targetValue), $targetValue);
                } else {
                    $collectionBatch[$idHash]->add($targetValue);
                }
            }
        }

        foreach ($collections as $association) {
            $association->setInitialized(true);
            $association->takeSnapshot();
        }
    }

    public function scheduleCollection(PersistentCollection $collection, ClassMetadata $sourceClass): void
    {
        $mapping = $collection->getMapping();
        assert($mapping instanceof ToManyInverseSideMapping);
        $name    = $mapping->sourceEntity . '#' . $mapping->fieldName;

        if (! isset($this->eagerLoadingCollections[$name])) {
            $this->eagerLoadingCollections[$name] = [
                'items'   => [],
                'mapping' => $mapping,
            ];
        }

        $owner = $collection->getOwner();
        assert($owner !== null);

        $id     = $this->identifierFlattener->flattenIdentifier(
            $sourceClass,
            $sourceClass->getIdentifierValues($owner),
        );
        $idHash = implode(' ', $id);

        $this->eagerLoadingCollections[$name]['items'][$idHash] = $collection;
    }
}
