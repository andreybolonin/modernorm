<?php

declare(strict_types=1);

namespace ModernORM\Tests\Support;

use Doctrine\Persistence\Mapping\ClassMetadata;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_cache_records')]
final class CacheRecord
{
    #[ORM\Id, ORM\Column]
    public int $id;

    #[ORM\Column(name: 'name_v1')]
    public string $name;
}

/** Simulate a mapping deployment for the same entity without redefining PHP classes. */
final class CountingCacheDriver extends AttributeDriver
{
    public int $loads = 0;

    public function __construct(private string $column = 'name_v1') { parent::__construct([]); }

    public function loadMetadataForClass(string $className, ClassMetadata $metadata): void
    {
        ++$this->loads;
        parent::loadMetadataForClass($className, $metadata);
        $metadata->setAttributeOverride('name', ['columnName' => $this->column]);
    }
}

/** Observe PSR-6 calls while preserving the real adapter's serialization and expiry. */
final class RecordingCachePool implements CacheItemPoolInterface
{
    /** @var list<array{string, bool}> */
    public array $reads = [];
    /** @var list<string> */
    public array $writes = [];

    public function __construct(private CacheItemPoolInterface $pool) {}

    public function getItem(string $key): CacheItemInterface
    {
        $item = $this->pool->getItem($key);
        $this->reads[] = [$key, $item->isHit()];
        return $item;
    }
    public function getItems(array $keys = []): iterable
    {
        foreach ($this->pool->getItems($keys) as $key => $item) {
            $this->reads[] = [$key, $item->isHit()];
            yield $key => $item;
        }
    }
    public function hasItem(string $key): bool { return $this->pool->hasItem($key); }
    public function clear(): bool { return $this->pool->clear(); }
    public function deleteItem(string $key): bool { return $this->pool->deleteItem($key); }
    public function deleteItems(array $keys): bool { return $this->pool->deleteItems($keys); }
    public function save(CacheItemInterface $item): bool
    {
        $this->writes[] = $item->getKey();
        return $this->pool->save($item);
    }
    public function saveDeferred(CacheItemInterface $item): bool
    {
        $this->writes[] = $item->getKey();
        return $this->pool->saveDeferred($item);
    }
    public function commit(): bool { return $this->pool->commit(); }
}
