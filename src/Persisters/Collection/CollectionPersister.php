<?php

declare(strict_types=1);

namespace ModernORM\Persisters\Collection;

use ModernORM\PersistentCollection;

/**
 * Define the behavior that should be implemented by all collection persisters.
 */
interface CollectionPersister
{
    /**
     * Deletes the persistent state represented by the given collection.
     */
    public function delete(PersistentCollection $collection): void;

    /**
     * Updates the given collection, synchronizing its state with the database
     * by inserting, updating and deleting individual elements.
     */
    public function update(PersistentCollection $collection): void;
}
