<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Exception\MappingException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity]
class BatchAttributeTarget { #[ORM\Id, ORM\Column] public int $id; }

#[ORM\MappedSuperclass]
abstract class BatchAttributeBase
{
    #[ORM\Id, ORM\Column] public int $id;
    #[\ModernORM\Mapping\ManyToOne(targetEntity: BatchAttributeTarget::class, batchSize: 64)]
    public BatchAttributeTarget $target;
}

#[ORM\Entity]
class BatchAttributeOwner extends BatchAttributeBase
{
    #[ORM\ManyToOne(targetEntity: BatchAttributeTarget::class)] public BatchAttributeTarget $defaultTarget;
    #[ORM\ManyToOne(targetEntity: BatchAttributeTarget::class, batchSize: 1)] public BatchAttributeTarget $disabledTarget;
}

final class BatchAttributeTest extends TestCase
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
            $metadata = $em->getClassMetadata(BatchAttributeOwner::class);
            self::assertSame(64, $metadata->associationMappings['target']->batchSize);
            self::assertNull($metadata->associationMappings['defaultTarget']->batchSize);
            self::assertSame(1, $metadata->associationMappings['disabledTarget']->batchSize);
            self::assertFalse($em->getConnection()->isConnected());
        }
    }

    public static function invalidSizes(): array { return [[0], [-1], [65536]]; }

    #[DataProvider('invalidSizes')]
    public function testInvalidProgrammaticMappingIsRejected(int $size): void
    {
        $metadata = new ORM\ClassMetadata(BatchAttributeOwner::class);
        $this->expectException(MappingException::class);
        $metadata->mapManyToOne(['fieldName' => 'target', 'targetEntity' => BatchAttributeTarget::class, 'batchSize' => $size]);
    }

    public function testCachedInvalidBatchSizeCannotBypassValidation(): void
    {
        $cache = new ArrayAdapter(storeSerialized: true);
        $metadata = $this->manager($cache)->getClassMetadata(BatchAttributeOwner::class);
        $metadata->associationMappings['target']->batchSize = 0;
        foreach ($cache->getValues() as $key => $value) {
            $stored = $cache->getItem($key)->get();
            if ($stored instanceof ORM\ClassMetadata && $stored->name === BatchAttributeOwner::class) {
                $cache->save($cache->getItem($key)->set($metadata));
            }
        }
        $this->expectException(MappingException::class);
        $this->manager($cache)->getClassMetadata(BatchAttributeOwner::class);
    }
}
