<?php

declare(strict_types=1);

namespace ModernORM\Tools\Pagination\Exception;

/** A malformed cursor or one that does not match the server-defined sorting fields. */
final class InvalidCursor extends \InvalidArgumentException
{
}
