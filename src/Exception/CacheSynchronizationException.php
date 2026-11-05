<?php

declare(strict_types=1);

namespace ModernORM\Exception;

/** Distinguishes a committed cache failure from unsupported incomplete transaction completion. */
final class CacheSynchronizationException extends \RuntimeException implements ORMException
{
    public readonly ?bool $databaseCommitted;
    public function __construct(\Throwable $previous, ?bool $databaseCommitted = true, public readonly ?\Throwable $cacheFailure = null)
    {
        $this->databaseCommitted = $databaseCommitted;
        $message = $databaseCommitted
            ? 'Database commit succeeded, but entity L2 cache invalidation failed. Discard this EntityManager and recover the cache; do not retry the SQL.'
            : 'Database outcome cannot be confirmed after COMMIT failure or transaction nesting changes. Discard this EntityManager, recover remaining transaction/cache state, and inspect the database before retrying SQL.';
        parent::__construct($message, 0, $previous);
    }
}
