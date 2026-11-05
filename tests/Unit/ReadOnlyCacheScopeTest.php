<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use ModernORM\Cache\CacheConfiguration;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Exception\MappingException;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\Driver\AttributeDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Cacheable(ttl: 17)]
class L2ScopeRecord { #[ORM\Id, ORM\Column] public int $id; }
#[ORM\Entity, ORM\Cacheable(ttl: 0)]
class L2ScopeInfinite { #[ORM\Id, ORM\Column] public int $id; }
#[ORM\Entity, ORM\Cacheable(ttl: 17, writePolicy: \ModernORM\Cache\CacheWritePolicy::INVALIDATE)]
class L2ScopeInvalidating { #[ORM\Id, ORM\Column] public int $id; }
#[ORM\Entity, ORM\Cacheable(writePolicy: 'invalidate')]
class L2ScopeStringPolicy { #[ORM\Id, ORM\Column] public int $id; }
#[ORM\Entity, ORM\Cacheable]
class L2BinaryRecord { #[ORM\Id, ORM\Column] public int $id; #[ORM\Column(type: 'blob')] public mixed $content; }
#[ORM\Entity, ORM\cAcHe(usage: 'READ_WRITE')]
class L2LegacyRecord { #[ORM\Id, ORM\Column] public int $id; }
#[ORM\Entity, ORM\Cacheable(ttl: -1)]
class L2NegativeTtl { #[ORM\Id, ORM\Column] public int $id; }
#[ORM\MappedSuperclass, ORM\Cacheable]
class L2CachedSuperclass { #[ORM\Id, ORM\Column] public int $id; }
#[ORM\Embeddable, ORM\Cacheable]
class L2CachedEmbeddable { #[ORM\Column] public string $value; }
#[ORM\Entity]
class L2CachedProperty
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToMany(targetEntity: L2ScopeRecord::class), ORM\Cacheable] public \Doctrine\Common\Collections\Collection $records;
}
#[ORM\Entity]
class L2LegacyCachedProperty
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToMany(targetEntity: L2ScopeRecord::class), ORM\Cache] public \Doctrine\Common\Collections\Collection $records;
}

final class ReadOnlyCacheScopeTest extends TestCase
{
    private function manager(?ArrayAdapter $metadata = null): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $config->setSecondLevelCache(new ArrayAdapter());
        if ($metadata !== null) { $config->setMetadataCache($metadata); }
        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '17']), $config);
    }

    public function testMissingPoolIsAnExplicitConfigurationError(): void
    {
        $config = new Configuration(); $config->setMetadataDriverImpl(new AttributeDriver([])); $config->setSecondLevelCacheEnabled();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PSR-6 pool');
        new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '17']), $config);
    }

    public function testTtlSurvivesMetadataSerializationWithoutRequiringImmutable(): void
    {
        $pool = new ArrayAdapter(storeSerialized: true);
        $first = $this->manager($pool)->getClassMetadata(L2ScopeRecord::class);
        $second = $this->manager($pool)->getClassMetadata(L2ScopeRecord::class);
        self::assertNotSame($first, $second);
        self::assertSame(['ttl' => 17, 'writePolicy' => \ModernORM\Cache\CacheWritePolicy::READ_ONLY], $second->cache);
        self::assertFalse($second->isReadOnly);
        self::assertSame(['ttl' => 0, 'writePolicy' => \ModernORM\Cache\CacheWritePolicy::READ_ONLY], $this->manager()->getClassMetadata(L2ScopeInfinite::class)->cache);
    }

    public function testResourceFieldCachingIsRejected(): void
    {
        $this->expectException(\LogicException::class); $this->expectExceptionMessage('binary/resource');
        $this->manager()->getUnitOfWork()->getEntityPersister(L2BinaryRecord::class);
    }

    public static function unsupportedAttributes(): array
    {
        return [
            [L2LegacyRecord::class, 'Cache was removed'],
            [L2NegativeTtl::class, 'TTL'],
            [L2CachedSuperclass::class, 'concrete Entity'],
            [L2CachedEmbeddable::class, 'concrete Entity'],
            [L2CachedProperty::class, 'concrete Entity'],
            [L2LegacyCachedProperty::class, 'Cache was removed'],
        ];
    }

    #[DataProvider('unsupportedAttributes')]
    public function testUnsupportedAttributesFailBeforeDatabaseAccess(string $class, string $message): void
    {
        $this->expectException(MappingException::class); $this->expectExceptionMessage($message);
        $this->manager()->getClassMetadata($class);
    }

    #[DataProvider('invalidMetadata')]
    public function testCachedOrListenerModifiedSettingsCannotReintroduceLegacyStrategies(array $cache): void
    {
        $pool = new ArrayAdapter(storeSerialized: true);
        $em = $this->manager($pool);
        $em->getClassMetadata(L2ScopeRecord::class);
        foreach ($pool->getValues() as $key => $value) {
            $item = $pool->getItem($key);
            if ($item->get() instanceof ClassMetadata) {
                $metadata = $item->get();
                $metadata->cache = $cache;
                $pool->save($item->set($metadata));
            }
        }
        $this->expectException(MappingException::class);
        $this->manager($pool)->getClassMetadata(L2ScopeRecord::class);
    }

    public static function invalidMetadata(): array
    {
        $readOnly = \ModernORM\Cache\CacheWritePolicy::READ_ONLY;
        return [[['usage' => 3, 'region' => 'legacy']], [['ttl' => -1, 'writePolicy' => $readOnly]],
            [['ttl' => '17', 'writePolicy' => $readOnly]], [['ttl' => 17]], [['ttl' => 17, 'writePolicy' => 'invalidate']]];
    }

    public function testWritePolicySurvivesSerializedMetadataAndDoesNotImplyImmutable(): void
    {
        $pool = new ArrayAdapter(storeSerialized: true);
        $this->manager($pool)->getClassMetadata(L2ScopeInvalidating::class);
        $metadata = $this->manager($pool)->getClassMetadata(L2ScopeInvalidating::class);
        self::assertSame(\ModernORM\Cache\CacheWritePolicy::INVALIDATE, $metadata->cache['writePolicy']);
        self::assertSame(17, $metadata->cache['ttl']);
        self::assertFalse($metadata->isReadOnly);
        // Metadata shape does not depend on associative array ordering.
        $metadata->cache = ['writePolicy' => $metadata->cache['writePolicy'], 'ttl' => 17];
        $metadata->assertSupportedMapping();
    }

    public function testStringWritePolicyIsRejectedBeforeDatabaseAccess(): void
    {
        $this->expectException(\TypeError::class);
        $this->manager()->getClassMetadata(L2ScopeStringPolicy::class);
    }

    public function testEmptyNamespaceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CacheConfiguration(new ArrayAdapter(), namespace: '');
    }
}
