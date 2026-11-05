<?php

declare(strict_types=1);

namespace ModernORM\Cache\Logging;

final class CacheLoggerChain implements CacheLogger
{
    /** @var array<string, CacheLogger> */
    private array $loggers = [];
    public function setLogger(string $name, CacheLogger $logger): void
    {
        $this->loggers[$name] = $logger;
    }
    public function getLogger(string $name): ?CacheLogger
    {
        return $this->loggers[$name] ?? null;
    }
    public function getLoggers(): array
    {
        return $this->loggers;
    }
    public function hit(string $entity): void
    {
        foreach ($this->loggers as $logger) {
            $logger->hit($entity);
        }
    }
    public function miss(string $entity): void
    {
        foreach ($this->loggers as $logger) {
            $logger->miss($entity);
        }
    }
    public function put(string $entity): void
    {
        foreach ($this->loggers as $logger) {
            $logger->put($entity);
        }
    }
    public function evict(string $entity): void
    {
        foreach ($this->loggers as $logger) {
            $logger->evict($entity);
        }
    }
}
