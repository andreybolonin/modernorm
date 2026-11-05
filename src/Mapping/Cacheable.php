<?php

declare(strict_types=1);

namespace ModernORM\Mapping;

use Attribute;
use ModernORM\Cache\CacheWritePolicy;

/** Entity L2 cache. Independent of Immutable; zero TTL delegates expiration to the pool. */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Cacheable implements MappingAttribute
{
    public function __construct(public int $ttl = 3600, public CacheWritePolicy $writePolicy = CacheWritePolicy::READ_ONLY)
    {
    }
}
