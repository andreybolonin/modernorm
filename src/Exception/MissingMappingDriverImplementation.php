<?php

declare(strict_types=1);

namespace ModernORM\Exception;

use LogicException;

final class MissingMappingDriverImplementation extends LogicException implements ManagerException
{
    public static function create(): self
    {
        return new self(
            "It's a requirement to specify a Metadata Driver and pass it " .
            'to ModernORM\\Configuration::setMetadataDriverImpl().',
        );
    }
}
