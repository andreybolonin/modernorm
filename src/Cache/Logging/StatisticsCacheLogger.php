<?php

declare(strict_types=1);

namespace ModernORM\Cache\Logging;

/** Reset at request/job boundaries in long-lived workers. */
final class StatisticsCacheLogger implements CacheLogger
{
    private array $hits = [];
    private array $misses = [];
    private array $puts = [];
    private array $evictions = [];

    public function hit(string $entity): void { $this->hits[$entity] = ($this->hits[$entity] ?? 0) + 1; }
    public function miss(string $entity): void { $this->misses[$entity] = ($this->misses[$entity] ?? 0) + 1; }
    public function put(string $entity): void { $this->puts[$entity] = ($this->puts[$entity] ?? 0) + 1; }
    public function evict(string $entity): void { $this->evictions[$entity] = ($this->evictions[$entity] ?? 0) + 1; }

    public function getHitCount(?string $entity = null): int { return $entity === null ? array_sum($this->hits) : ($this->hits[$entity] ?? 0); }
    public function getMissCount(?string $entity = null): int { return $entity === null ? array_sum($this->misses) : ($this->misses[$entity] ?? 0); }
    public function getPutCount(?string $entity = null): int { return $entity === null ? array_sum($this->puts) : ($this->puts[$entity] ?? 0); }
    public function getEvictionCount(?string $entity = null): int { return $entity === null ? array_sum($this->evictions) : ($this->evictions[$entity] ?? 0); }
    public function reset(): void { $this->hits = $this->misses = $this->puts = $this->evictions = []; }
}
