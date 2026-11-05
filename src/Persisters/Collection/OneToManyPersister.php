<?php

declare(strict_types=1);

namespace ModernORM\Persisters\Collection;

use BadMethodCallException;
use Doctrine\DBAL\Exception as DBALException;
use ModernORM\Exception\EntityNotFoundException;
use ModernORM\Exception\MappingException;
use ModernORM\Mapping\OneToManyAssociationMapping;
use ModernORM\PersistentCollection;
use ModernORM\Utility\PersisterHelper;

use function assert;
use function count;
use function implode;
use function is_int;

/**
 * Persister for one-to-many collections.
 */
class OneToManyPersister extends AbstractCollectionPersister
{
    public function delete(PersistentCollection $collection): void
    {
        // The only valid case here is when you have weak entities. In this
        // scenario, you have @OneToMany with orphanRemoval=true, and replacing
        // the entire collection with a new would trigger this operation.
        $mapping = $this->getMapping($collection);

        if (! $mapping->orphanRemoval) {
            // Handling non-orphan removal should never happen, as @OneToMany
            // can only be inverse side. For owning side one to many, it is
            // required to have a join table, which would classify as a ManyToManyPersister.
            return;
        }

        $this->deleteEntityCollection($collection);
    }

    public function update(PersistentCollection $collection): void
    {
        // This can never happen. One to many can only be inverse side.
        // For owning side one to many, it is required to have a join table,
        // then classifying it as a ManyToManyPersister.
        return;
    }

    public function get(PersistentCollection $collection, mixed $index): object|null
    {
        $mapping = $this->getMapping($collection);

        if (! $mapping->isIndexed()) {
            throw new BadMethodCallException('Selecting a collection by index is only supported on indexed collections.');
        }

        $persister = $this->uow->getEntityPersister($mapping->targetEntity);

        return $persister->load(
            [
                $mapping->mappedBy  => $collection->getOwner(),
                $mapping->indexBy() => $index,
            ],
            null,
            $mapping,
            [],
            null,
            1,
        );
    }

    public function count(PersistentCollection $collection): int
    {
        $mapping   = $this->getMapping($collection);
        $persister = $this->uow->getEntityPersister($mapping->targetEntity);

        return $persister->count([$mapping->mappedBy => $collection->getOwner()]);
    }

    /**
     * {@inheritDoc}
     */
    public function slice(PersistentCollection $collection, int $offset, int|null $length = null): array
    {
        $mapping   = $this->getMapping($collection);
        $persister = $this->uow->getEntityPersister($mapping->targetEntity);

        return $persister->getOneToManyCollection($mapping, $collection->getOwner(), $offset, $length);
    }

    public function containsKey(PersistentCollection $collection, mixed $key): bool
    {
        $mapping = $this->getMapping($collection);

        if (! $mapping->isIndexed()) {
            throw new BadMethodCallException('Selecting a collection by index is only supported on indexed collections.');
        }

        $persister = $this->uow->getEntityPersister($mapping->targetEntity);

        return (bool) $persister->count([
            $mapping->mappedBy => $collection->getOwner(),
            $mapping->indexBy() => $key,
        ]);
    }

    public function contains(PersistentCollection $collection, object $element): bool
    {
        if (! $this->isValidEntityState($element)) {
            return false;
        }

        $mapping   = $this->getMapping($collection);
        $persister = $this->uow->getEntityPersister($mapping->targetEntity);

        return $persister->exists($element, [$mapping->mappedBy => $collection->getOwner()]);
    }

    /**
     * @throws DBALException
     * @throws EntityNotFoundException
     * @throws MappingException
     */
    private function deleteEntityCollection(PersistentCollection $collection): int
    {
        $mapping     = $this->getMapping($collection);
        $identifier  = $this->uow->getEntityIdentifier($collection->getOwner());
        $sourceClass = $this->em->getClassMetadata($mapping->sourceEntity);
        $targetClass = $this->em->getClassMetadata($mapping->targetEntity);
        $columns     = [];
        $parameters  = [];
        $types       = [];

        foreach ($this->em->getMetadataFactory()->getOwningSide($mapping)->joinColumns as $joinColumn) {
            $columns[]    = $this->quoteStrategy->getJoinColumnName($joinColumn, $targetClass, $this->platform);
            $parameters[] = $identifier[$sourceClass->getFieldForColumn($joinColumn->referencedColumnName)];
            $types[]      = PersisterHelper::getTypeOfColumn($joinColumn->referencedColumnName, $sourceClass, $this->em);
        }

        $statement = 'DELETE FROM ' . $this->quoteStrategy->getTableName($targetClass, $this->platform)
            . ' WHERE ' . implode(' = ? AND ', $columns) . ' = ?';

        $numAffected = $this->conn->executeStatement($statement, $parameters, $types);

        assert(is_int($numAffected));

        return $numAffected;
    }

    /**
     * Delete Class Table Inheritance entities.
     * A temporary table is needed to keep IDs to be deleted in both parent and child class' tables.
     *
     * Thanks Steve Ebersole (Hibernate) for idea on how to tackle reliably this scenario, we owe him a beer! =)
     *
     * @throws DBALException
     */

    private function getMapping(PersistentCollection $collection): OneToManyAssociationMapping
    {
        $mapping = $collection->getMapping();

        assert($mapping->isOneToMany());

        return $mapping;
    }
}
