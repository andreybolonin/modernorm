<?php

declare(strict_types=1);

namespace ModernORM\Exception;

/** A post-write failure or an uncertain commit; never automatically retry the DML. */
final class BulkOperationException extends \RuntimeException implements ORMException
{
    public function __construct(
        public readonly ?bool $databaseCommitted,
        \Throwable $previous,
    ) {
        parent::__construct(
            $databaseCommitted === true
                ? 'Bulk SQL committed, but cache invalidation or CLEAR failed. Discard this EntityManager; do not retry the SQL.'
                : 'Bulk database outcome is unconfirmed or belongs to a pending outer transaction. Discard this EntityManager and inspect the transaction/database before retrying SQL.',
            0,
            $previous,
        );
    }
}
