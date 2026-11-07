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

namespace ModernORM\Cache\Region;

use ModernORM\Cache\CacheEntry;
use ModernORM\Cache\EntityCacheKey;
use ModernORM\Exception\CacheException;
use Psr\Cache\CacheItemPoolInterface;

/** @internal Generation-scoped keys invalidate in-flight fills without clearing unrelated PSR-6 data. */
final class ReadOnlyRegion
{
    private readonly string $prefix;
    public function __construct(private readonly string $name, private readonly CacheItemPoolInterface $pool, private readonly int $lifetime = 3600, private readonly ?\ModernORM\Cache\Logging\CacheLogger $logger = null, private readonly ?string $entity = null)
    {
        if ($lifetime < 0) {
            throw new \InvalidArgumentException('Cache lifetime must not be negative.');
        }
        $this->prefix = 'morm_l2_ro_v1_' . hash('sha256', $name);
    }
    public function getName(): string
    {
        return $this->name;
    }
    public function ticket(EntityCacheKey $key): string
    {
        $region = $this->token($this->prefix . '_region');
        $base = $this->prefix . '_' . $region . '_' . hash('sha256', $key->hash);
        return hash('sha256', $base . '_' . $this->token($base . '_entry'));
    }
    public function getAt(string $ticket): ?CacheEntry
    {
        $item = $this->pool->getItem($ticket);
        return $item->isHit() && $item->get() instanceof CacheEntry ? $item->get() : null;
    }
    public function putAt(string $ticket, CacheEntry $entry): bool
    {
        $item = $this->pool->getItem($ticket)->set($entry);
        $item->expiresAfter($this->lifetime > 0 ? $this->lifetime : null);
        if (! $this->pool->save($item)) {
            throw new CacheException('Unable to store a READ_ONLY cache entry.');
        }
        return true;
    }
    public function get(EntityCacheKey $key): ?CacheEntry
    {
        return $this->getAt($this->ticket($key));
    }
    public function contains(EntityCacheKey $key): bool
    {
        return $this->get($key) !== null;
    }
    public function put(EntityCacheKey $key, CacheEntry $entry): bool
    {
        return $this->putAt($this->ticket($key), $entry);
    }
    public function evict(EntityCacheKey $key): bool
    {
        $region = $this->token($this->prefix . '_region');
        $this->rotate($this->prefix . '_' . $region . '_' . hash('sha256', $key->hash) . '_entry');
        $this->logger?->evict($this->entity ?? $this->name);
        return true;
    }
    public function evictAll(): bool
    {
        $this->rotate($this->prefix . '_region');
        $this->logger?->evict($this->entity ?? $this->name);
        return true;
    }
    private function token(string $key): string
    {
        $item = $this->pool->getItem(hash('sha256', $key));
        $token = $item->get();
        return $item->isHit() && is_string($token) && preg_match('/^[a-f0-9]{32}$/D', $token) === 1
            ? $token : $this->rotate($key);
    }
    private function rotate(string $key): string
    {
        $token = bin2hex(random_bytes(16));
        $item = $this->pool->getItem(hash('sha256', $key))->set($token)->expiresAfter(null);
        if (! $this->pool->save($item)) {
            throw new CacheException('Unable to invalidate a READ_ONLY cache generation.');
        }
        return $token;
    }
}
