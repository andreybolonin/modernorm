<?php

declare(strict_types=1);

namespace ModernORM\Persisters\Collection;

use Doctrine\DBAL\Exception as DBALException;
use ModernORM\Exception\EntityNotFoundException;
use ModernORM\Exception\MappingException;
use ModernORM\Mapping\OneToManyAssociationMapping;
use ModernORM\PersistentCollection;
use ModernORM\Utility\PersisterHelper;

use function assert;
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

    private function getMapping(PersistentCollection $collection): OneToManyAssociationMapping
    {
        $mapping = $collection->getMapping();

        assert($mapping->isOneToMany());

        return $mapping;
    }
}
