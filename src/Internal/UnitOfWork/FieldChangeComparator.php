<?php

/*
 * This file is part of the ModernORM package.
 *
 * (c) Andrey Bolonin <andreybolonin1989@gmail.com>
 * (c) Doctrine Project
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ModernORM\Internal\UnitOfWork;

use BackedEnum;

use function is_array;

/** @internal Compares PHP values without metadata access, conversion or snapshot mutation. */
final class FieldChangeComparator
{
    /** @return array{mixed, mixed}|null */
    public static function compare(mixed $original, mixed $current, bool $enum = false): ?array
    {
        if ($enum) {
            if (is_array($original)) {
                foreach ($original as $key => $value) {
                    if ($value instanceof BackedEnum) {
                        $original[$key] = $value->value;
                    }
                }
            } elseif ($original instanceof BackedEnum) {
                $original = $original->value;
            }
        }

        return $original === $current ? null : [$original, $current];
    }
}
