<?php

declare(strict_types=1);

namespace ModernORM\Persisters\Collection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use ModernORM\EntityManagerInterface;
use ModernORM\Mapping\QuoteStrategy;
use ModernORM\UnitOfWork;

/**
 * Base class for all collection persisters.
 */
abstract class AbstractCollectionPersister implements CollectionPersister
{
    protected Connection $conn;
    protected UnitOfWork $uow;
    protected AbstractPlatform $platform;
    protected QuoteStrategy $quoteStrategy;

    /**
     * Initializes a new instance of a class derived from AbstractCollectionPersister.
     */
    public function __construct(
        protected EntityManagerInterface $em,
    ) {
        $this->uow           = $em->getUnitOfWork();
        $this->conn          = $em->getConnection();
        $this->platform      = $this->conn->getDatabasePlatform();
        $this->quoteStrategy = $em->getConfiguration()->getQuoteStrategy();
    }

}
