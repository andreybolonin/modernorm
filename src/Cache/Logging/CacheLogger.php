<?php

declare(strict_types=1);

namespace ModernORM\Cache\Logging;

/** Entity-class metrics only: no public regions or identifier labels. */
interface CacheLogger
{
    public function hit(string $entity): void;
    public function miss(string $entity): void;
    public function put(string $entity): void;
    public function evict(string $entity): void;
}
