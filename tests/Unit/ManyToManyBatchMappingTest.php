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
abstract class ManyBatchMetadataBase
{
    #[ORM\Id, ORM\Column] public int $id;
    #[\ModernORM\Mapping\ManyToMany(targetEntity: ManyBatchMetadataRole::class, inversedBy: 'owners', batchSize: 64)] public Collection $roles;
}
#[ORM\Entity]
class ManyBatchMetadataOwner extends ManyBatchMetadataBase
{
    #[ORM\ManyToMany(targetEntity: ManyBatchMetadataRole::class)] public Collection $defaultRoles;
    #[ORM\ManyToMany(targetEntity: ManyBatchMetadataRole::class, batchSize: 1)] public Collection $disabledRoles;
}
#[ORM\Entity]
class ManyBatchMetadataRole
{
    #[ORM\Id, ORM\Column] public string $id;
    #[ORM\ManyToMany(targetEntity: ManyBatchMetadataOwner::class, mappedBy: 'roles', batchSize: 8)] public Collection $owners;
}
#[ORM\Entity]
class ManyBatchInvalidMetadata
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToMany(targetEntity: ManyBatchMetadataRole::class, batchSize: 0)] public Collection $roles;
}

final class ManyToManyBatchMappingTest extends TestCase
{
    private function manager(ArrayAdapter $cache): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $config->setMetadataCache($cache);
        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '17']), $config);
    }

    public function testOwningInverseAndInheritedMetadataSurviveSerialization(): void
    {
        $cache = new ArrayAdapter(storeSerialized: true);
        foreach ([$this->manager($cache), $this->manager($cache)] as $em) {
            $owner = $em->getClassMetadata(ManyBatchMetadataOwner::class);
            $role = $em->getClassMetadata(ManyBatchMetadataRole::class);
            self::assertSame(64, $owner->associationMappings['roles']->batchSize);
            self::assertNull($owner->associationMappings['defaultRoles']->batchSize);
            self::assertSame(1, $owner->associationMappings['disabledRoles']->batchSize);
            self::assertSame(8, $role->associationMappings['owners']->batchSize);
            self::assertFalse($em->getConnection()->isConnected());
        }
    }

    public static function invalidSizes(): array
    {
        return [[0, false], [-1, false], [65536, false], [0, true], [-1, true], [65536, true]];
    }

    #[DataProvider('invalidSizes')]
    public function testInvalidOwningAndInverseBatchSizesAreRejected(int $size, bool $inverse): void
    {
        $metadata = new ORM\ClassMetadata(ManyBatchMetadataOwner::class);
        $this->expectException(MappingException::class);
        $metadata->mapManyToMany(['fieldName' => 'roles', 'targetEntity' => ManyBatchMetadataRole::class, 'batchSize' => $size] + ($inverse ? ['mappedBy' => 'owners'] : []));
    }

    public function testInvalidAttributeFailsBeforeConnecting(): void
    {
        $em = $this->manager(new ArrayAdapter());
        try {
            $em->getClassMetadata(ManyBatchInvalidMetadata::class);
            self::fail('Expected invalid attribute');
        } catch (MappingException $exception) {
            self::assertStringContainsString('ManyToMany batchSize', $exception->getMessage());
        }
        self::assertFalse($em->getConnection()->isConnected());
    }

    public function testCachedInvalidSizeCannotBypassValidation(): void
    {
        $cache = new ArrayAdapter(storeSerialized: true);
        $metadata = $this->manager($cache)->getClassMetadata(ManyBatchMetadataRole::class);
        $metadata->associationMappings['owners']->batchSize = 0;
        foreach ($cache->getValues() as $key => $value) {
            $stored = $cache->getItem($key)->get();
            if ($stored instanceof ORM\ClassMetadata && $stored->name === $metadata->name) {
                $cache->save($cache->getItem($key)->set($metadata));
            }
        }
        $this->expectException(MappingException::class);
        $this->manager($cache)->getClassMetadata(ManyBatchMetadataRole::class);
    }
}
