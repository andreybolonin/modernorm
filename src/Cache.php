<?php

declare(strict_types=1);

namespace ModernORM;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use ModernORM\Cache\CacheConfiguration;
use ModernORM\Cache\EntityCacheKey;
use ModernORM\Cache\Persister\Entity\CachedEntityPersister;
use ModernORM\Cache\Region\ReadOnlyRegion;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Persisters\Entity\EntityPersister;

/** Entity-only L2 facade. Regions, generations and persister wiring are internal. */
final class Cache
{
    /** @var array<class-string, ReadOnlyRegion> */
    private array $regions = [];

    /** @internal Constructed by EntityManager from its configuration. */
    public function __construct(private readonly EntityManagerInterface $em, private readonly CacheConfiguration $configuration) {}

    public function contains(string $entity, mixed $id): bool
    {
        $metadata = $this->em->getClassMetadata($entity);
        return $this->region($metadata)?->contains($this->key($metadata, $id)) ?? false;
    }

    public function evict(string $entity, mixed $id): void
    {
        $metadata = $this->em->getClassMetadata($entity);
        $this->region($metadata)?->evict($this->key($metadata, $id));
    }

    /** Invalidate one mapped entity class, without clearing other classes or the shared PSR-6 pool. */
    public function evictAll(string $entity): void
    {
        $this->region($this->em->getClassMetadata($entity))?->evictAll();
    }

    /** @internal Preflight the full bulk write set before any write/invalidation. */
    public function assertSupported(ClassMetadata $metadata): void
    {
        $this->region($metadata);
    }

    /** @internal UnitOfWork integration; not a user-supplied factory extension point. */
    public function wrapPersister(EntityPersister $persister, ClassMetadata $metadata): EntityPersister
    {
        $region = $this->region($metadata);
        return $region === null ? $persister : new CachedEntityPersister($persister, $region, $this->em, $metadata);
    }

    private function region(ClassMetadata $metadata): ?ReadOnlyRegion
    {
        $metadata->assertSupportedMapping();
        if ($metadata->cache === null) {
            return null;
        }
        if ($metadata->associationMappings !== []) {
            throw new \LogicException('Entity L2 caching supports entities without declared associations only.');
        }
        foreach ($metadata->fieldMappings as $field) {
            if (in_array(Type::getType($field->type)->getBindingType(), [ParameterType::BINARY, ParameterType::LARGE_OBJECT], true)) {
                throw new \LogicException('Entity L2 caching does not support binary/resource fields.');
            }
        }
        return $this->regions[$metadata->name] ??= new ReadOnlyRegion(
            serialize([$this->configuration->namespace, $metadata->name]),
            $this->configuration->pool,
            $metadata->cache['ttl'],
            $this->configuration->logger,
            $metadata->name,
        );
    }

    private function key(ClassMetadata $metadata, mixed $id): EntityCacheKey
    {
        $field = $metadata->getSingleIdentifierFieldName();
        if (is_array($id)) {
            if (array_keys($id) !== [$field]) {
                throw new \InvalidArgumentException('L2 identifier must contain exactly the mapped primary key field.');
            }
            $id = $id[$field];
        }
        if (!$id instanceof \BackedEnum && !is_int($id) && !is_string($id)) {
            throw new \InvalidArgumentException('L2 identifier must be an integer, string or backed enum.');
        }
        return new EntityCacheKey($metadata->name, [$field => $id]);
    }
}
