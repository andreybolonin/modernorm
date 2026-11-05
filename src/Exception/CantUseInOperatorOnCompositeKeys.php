<?php

declare(strict_types=1);

namespace ModernORM\Exception;


class CantUseInOperatorOnCompositeKeys extends PersisterException
{
    public static function create(): self
    {
        return new self("Can't use IN operator on entities that have composite keys.");
    }
}
