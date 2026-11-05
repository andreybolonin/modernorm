<?php

declare(strict_types=1);

namespace ModernORM\Cache;

use ModernORM\Cache\Logging\CacheLogger;
use Psr\Cache\CacheItemPoolInterface;

/** PSR-6 storage and deployment isolation for entity L2 caching. TTL belongs to Cacheable metadata. */
final readonly class CacheConfiguration
{
    public function __construct(
        public CacheItemPoolInterface $pool,
        public string $namespace = 'modernorm',
        public ?CacheLogger $logger = null,
    ) {
        if (trim($namespace) === '') {
            throw new \InvalidArgumentException('L2 namespace must not be empty.');
        }
    }
}
