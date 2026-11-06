<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use ModernORM\Behavior\SoftDeleteTrait;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Exception\MappingException;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\SoftDelete]
class SoftMappingEntity
{
    use SoftDeleteTrait;
    #[ORM\Id, ORM\Column] public int $id;
}

#[ORM\Entity]
class NullableSoftMappingSource
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToOne(targetEntity: SoftMappingEntity::class, batchSize: 8)] public ?SoftMappingEntity $target = null;
}

#[ORM\Entity, ORM\SoftDelete, ORM\Immutable]
class ImmutableSoftMapping
{
    use SoftDeleteTrait;
    #[ORM\Id, ORM\Column] public int $id;
}

#[ORM\Entity, ORM\SoftDelete, ORM\Cacheable]
class ReadOnlyCacheSoftMapping
{
    use SoftDeleteTrait;
    #[ORM\Id, ORM\Column] public int $id;
}

#[ORM\MappedSuperclass, ORM\SoftDelete]
abstract class SoftMappingSuperclass
{
    use SoftDeleteTrait;
}

#[ORM\Embeddable, ORM\SoftDelete]
class SoftMappingEmbeddable
{
    use SoftDeleteTrait;
}

#[ORM\Entity, ORM\SoftDelete]
class MissingSoftMappingField
{
    #[ORM\Id, ORM\Column] public int $id;
}

#[ORM\Entity]
class RequiredSoftMappingSource
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToOne(targetEntity: SoftMappingEntity::class)]
    #[ORM\JoinColumn(nullable: false)]
    public SoftMappingEntity $target;
}

#[ORM\Entity]
class RequiredPhpSoftMappingSource
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToOne(targetEntity: SoftMappingEntity::class)]
    public SoftMappingEntity $target;
}

#[ORM\Entity]
class CascadeSoftMappingSource
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToOne(targetEntity: SoftMappingEntity::class, cascade: ['remove'])]
    public ?SoftMappingEntity $target = null;
}

#[ORM\Entity]
class ExtraLazySoftMappingSource
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToMany(targetEntity: SoftMappingEntity::class, fetch: 'EXTRA_LAZY')]
    public \Doctrine\Common\Collections\Collection $targets;
}

final class SoftDeleteMappingTest extends TestCase
{
    private function manager(ArrayAdapter $cache): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $config->setMetadataCache($cache);
        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '17']), $config);
    }

    public function testPolicyAndEagerAssociationsSurviveSerializedMetadataCache(): void
    {
        $cache = new ArrayAdapter(storeSerialized: true);
        foreach ([$this->manager($cache), $this->manager($cache)] as $em) {
            self::assertSame('deletedAt', $em->getClassMetadata(SoftMappingEntity::class)->softDeleteField);
            $source = $em->getClassMetadata(NullableSoftMappingSource::class);
            self::assertSame(['target'], $source->softDeleteToOneAssociations);
            self::assertSame(ORM\ClassMetadata::FETCH_EAGER, $source->associationMappings['target']->fetch);
            self::assertFalse($em->getConnection()->isConnected());
        }
    }

    public static function invalidMappings(): array
    {
        return [
            [ImmutableSoftMapping::class, 'mutable concrete Entity'],
            [ReadOnlyCacheSoftMapping::class, 'CacheWritePolicy::INVALIDATE'],
            [SoftMappingSuperclass::class, 'mutable concrete Entity'],
            [SoftMappingEmbeddable::class, 'mutable concrete Entity'],
            [MissingSoftMappingField::class, 'nullable, writable datetime_immutable'],
            [RequiredSoftMappingSource::class, 'nullable to-one'],
            [RequiredPhpSoftMappingSource::class, 'nullable PHP'],
            [CascadeSoftMappingSource::class, 'cascade remove or orphanRemoval'],
            [ExtraLazySoftMappingSource::class, 'EXTRA_LAZY'],
        ];
    }

    #[DataProvider('invalidMappings')]
    public function testUnsupportedScenariosAreRejected(string $class, string $message): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage($message);
        $this->manager(new ArrayAdapter())->getClassMetadata($class);
    }

    public function testVisibilityMustBeChosenBeforeBuildingProjection(): void
    {
        $query = $this->manager(new ArrayAdapter())->createQueryBuilder()->fromEntity(SoftMappingEntity::class, 's');
        $this->expectException(\LogicException::class);
        $query->withDeleted();
    }
}
