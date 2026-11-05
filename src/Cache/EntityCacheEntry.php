<?php

declare(strict_types=1);

namespace ModernORM\Cache;

class EntityCacheEntry implements CacheEntry
{
    /**
 * @internal
     * @param class-string        $class The entity class name
     * @param array<string,mixed> $data  The entity map data
     */
    public function __construct(
        public readonly string $class,
        public readonly array $data,
    ) {
    }

}
