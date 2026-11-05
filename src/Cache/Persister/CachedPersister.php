<?php

declare(strict_types=1);

namespace ModernORM\Cache\Persister;

use ModernORM\Cache\Region\ReadOnlyRegion;

/**
 * @internal Transaction callbacks for the entity cache wrapper.
 */
interface CachedPersister
{
    /**
     * Perform whatever processing is encapsulated here after completion of the transaction.
     */
    public function afterTransactionComplete(): void;

    /**
     * Perform whatever processing is encapsulated here after completion of the rolled-back.
     */
    public function afterTransactionRolledBack(): void;

    /** Evict all pending write keys after uncertain COMMIT; report whether any keys existed. */
    public function invalidatePendingWrites(): bool;

    public function getCacheRegion(): ReadOnlyRegion;
}
