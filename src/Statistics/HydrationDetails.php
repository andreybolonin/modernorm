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

final readonly class HydrationDetails
{
    public function __construct(
        public string $hydrator,
        public bool $streamed,
        public int $rows,
        public int $entitiesCreated,
        public bool $completed,
    ) {
    }
}
