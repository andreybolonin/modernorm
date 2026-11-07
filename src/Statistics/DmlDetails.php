<?php

/*
 * This file is part of the ModernORM package.
 *
 * (c) Andrey Bolonin <andreybolonin1989@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ModernORM\Statistics;

use Doctrine\DBAL\Driver\Result;
use Throwable;

/** Client statement outcome only; no SQL, identifiers, values or transaction-commit claim. */
final readonly class DmlDetails
{
    public function __construct(public DmlOperation $operation, public ?int $affectedRows)
    {
    }

    /** @internal Reading optional telemetry must not turn a successful write into an application error. */
    public static function rows(Result|int|string $result): ?int
    {
        try {
            $count = $result instanceof Result ? $result->rowCount() : $result;
            $value = filter_var($count, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            return $value === false ? null : $value;
        } catch (Throwable) {
            return null;
        }
    }
}
