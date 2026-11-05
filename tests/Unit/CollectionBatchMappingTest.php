<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Exception\MappingException;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\MappedSuperclass]
abstract class CollectionBatchMappingBase
{
    #[ORM\Id, ORM\Column] public int $id;
    #[\ModernORM\Mapping\OneToMany(mappedBy: 'owner', targetEntity: CollectionBatchMappingChild::class, batchSize: 64)]
    public Collection $items;
}
#[ORM\Entity]
class CollectionBatchMappingOwner extends CollectionBatchMappingBase
{
    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: CollectionBatchMappingChild::class)] public Collection $defaultItems;
    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: CollectionBatchMappingChild::class, batchSize: 1)] public Collection $disabledItems;
}
#[ORM\Entity]
class CollectionBatchMappingChild
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToOne(targetEntity: CollectionBatchMappingOwner::class)] public CollectionBatchMappingOwner $owner;
}
#[ORM\Entity]
class CollectionBatchInvalidOwner
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: CollectionBatchMappingChild::class, batchSize: 0)] public Collection $items;
}

final class CollectionBatchMappingTest extends TestCase
{
    private function manager(ArrayAdapter $cache): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $config->setMetadataCache($cache);
        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '17']), $config);
    }

    public function testInheritedAttributeSurvivesSerializedMetadataCache(): void
    {
        $cache = new ArrayAdapter(storeSerialized: true);
        foreach ([$this->manager($cache), $this->manager($cache)] as $em) {
            $metadata = $em->getClassMetadata(CollectionBatchMappingOwner::class);
            self::assertSame(64, $metadata->associationMappings['items']->batchSize);
            self::assertNull($metadata->associationMappings['defaultItems']->batchSize);
            self::assertSame(1, $metadata->associationMappings['disabledItems']->batchSize);
            self::assertFalse($em->getConnection()->isConnected());
        }
    }

    public static function invalidSizes(): array
    {
        return [[0], [-1], [65536]];
    }

    #[DataProvider('invalidSizes')]
    public function testInvalidBatchSizeIsRejected(int $size): void
    {
        $metadata = new ORM\ClassMetadata(CollectionBatchMappingOwner::class);
        $this->expectException(MappingException::class);
        $metadata->mapOneToMany(['fieldName' => 'items', 'mappedBy' => 'owner', 'targetEntity' => CollectionBatchMappingChild::class, 'batchSize' => $size]);
    }

    public function testInvalidAttributeFailsBeforeConnecting(): void
    {
        $em = $this->manager(new ArrayAdapter());
        try {
            $em->getClassMetadata(CollectionBatchInvalidOwner::class);
            self::fail('Expected invalid batch size');
        } catch (MappingException $exception) {
            self::assertStringContainsString('OneToMany batchSize', $exception->getMessage());
        }
        self::assertFalse($em->getConnection()->isConnected());
    }

    public function testInvalidCachedBatchSizeCannotBypassValidation(): void
    {
        $cache = new ArrayAdapter(storeSerialized: true);
        $metadata = $this->manager($cache)->getClassMetadata(CollectionBatchMappingOwner::class);
        $metadata->associationMappings['items']->batchSize = 0;
        foreach ($cache->getValues() as $key => $value) {
            $stored = $cache->getItem($key)->get();
            if ($stored instanceof ORM\ClassMetadata && $stored->name === $metadata->name) {
                $cache->save($cache->getItem($key)->set($metadata));
            }
        }
        $this->expectException(MappingException::class);
        $this->manager($cache)->getClassMetadata(CollectionBatchMappingOwner::class);
    }
}
