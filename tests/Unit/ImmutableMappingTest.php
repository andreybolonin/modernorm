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

#[\ModernORM\Mapping\Entity, ORM\Immutable]
final class ReadOnlyMappingRecord
{
    #[ORM\Id, ORM\Column] public int $id;
}

#[ORM\MappedSuperclass, ORM\Immutable]
abstract class InvalidReadOnlySuperclass
{
    #[ORM\Id, ORM\Column] public int $id;
}

#[ORM\Embeddable, ORM\Immutable]
final class InvalidReadOnlyEmbeddable
{
    #[ORM\Column] public string $value;
}

final class ImmutableMappingTest extends TestCase
{
    private function manager(ArrayAdapter $cache): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $config->setMetadataCache($cache);
        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '17']), $config);
    }

    public function testMarkerSurvivesSerializedMetadataCacheWithEntityAttribute(): void
    {
        $cache = new ArrayAdapter(storeSerialized: true);
        foreach ([$this->manager($cache), $this->manager($cache)] as $em) {
            self::assertTrue($em->getClassMetadata(ReadOnlyMappingRecord::class)->isReadOnly);
            self::assertFalse($em->getConnection()->isConnected());
        }
    }

    public static function invalidMappings(): array
    {
        return [[InvalidReadOnlySuperclass::class], [InvalidReadOnlyEmbeddable::class]];
    }

    #[DataProvider('invalidMappings')]
    public function testImmutableRequiresConcreteEntity(string $class): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('Immutable must be applied to a concrete Entity');
        $this->manager(new ArrayAdapter())->getClassMetadata($class);
    }
}
