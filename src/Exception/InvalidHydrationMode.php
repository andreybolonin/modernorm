<?php

declare(strict_types=1);

namespace ModernORM\Exception;

use LogicException;

use function sprintf;

final class InvalidHydrationMode extends LogicException implements ManagerException
{
    public static function fromMode(string $mode): self
    {
        return new self(sprintf('"%s" is an invalid hydration mode.', $mode));
    }
}
