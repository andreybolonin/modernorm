<?php

declare(strict_types=1);

namespace ModernORM\Internal\Hydration;

use Doctrine\DBAL\Driver\Exception;
use ModernORM\Exception\MultipleSelectorsFoundException;

use function count;

/**
 * Hydrator that produces one-dimensional array.
 */
final class ScalarColumnHydrator extends AbstractHydrator
{
    /**
     * {@inheritDoc}
     *
     * @throws MultipleSelectorsFoundException
     * @throws Exception
     */
    protected function hydrateAllData(): array
    {
        if (count($this->resultSetMapping()->fieldMappings) > 1) {
            throw MultipleSelectorsFoundException::create($this->resultSetMapping()->fieldMappings);
        }

        return $this->statement()->fetchFirstColumn();
    }
}
