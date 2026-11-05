<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use ModernORM\Behavior\BlameableInterface;
use ModernORM\Behavior\BlameableTrait;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Exception\MappingException;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity]
final class MappingActor
{
    #[ORM\Id, ORM\Column]
    public int $id;
}

#[ORM\Entity, ORM\Blameable(actorClass: MappingActor::class)]
final class AutomaticBlameable implements BlameableInterface
{
    use BlameableTrait;

    #[ORM\Id, ORM\Column]
    public int $id;
}

#[ORM\Entity, ORM\Blameable(actorClass: MappingActor::class)]
final class ExplicitBlameable implements BlameableInterface
{
    use BlameableTrait;

    #[ORM\Id, ORM\Column]
    public int $id;

    #[ORM\ManyToOne(targetEntity: MappingActor::class, batchSize: 32)]
    #[ORM\JoinColumn(name: 'author_id', onDelete: 'RESTRICT')]
    protected ?object $createdBy = null;
}

#[ORM\Entity, ORM\Blameable(actorClass: MappingActor::class)]
#[ORM\AssociationOverrides([
    new ORM\AssociationOverride(name: 'createdBy', joinColumns: new ORM\JoinColumn(name: 'creator_id', nullable: true, onDelete: 'RESTRICT')),
])]
final class OverriddenBlameable implements BlameableInterface
{
    use BlameableTrait;

    #[ORM\Id, ORM\Column]
    public int $id;
}

#[ORM\Entity, ORM\Blameable(actorClass: MappingActor::class)]
final class MissingBlameableContract
{
    use BlameableTrait;

    #[ORM\Id, ORM\Column]
    public int $id;
}

#[ORM\MappedSuperclass, ORM\Blameable(actorClass: MappingActor::class)]
abstract class BlameableSuperclass implements BlameableInterface
{
    use BlameableTrait;
}

#[ORM\Embeddable, ORM\Blameable(actorClass: MappingActor::class)]
final class BlameableEmbeddable implements BlameableInterface
{
    use BlameableTrait;
}

#[ORM\Entity, ORM\Blameable(actorClass: \stdClass::class)]
final class NonEntityBlameableActor implements BlameableInterface
{
    use BlameableTrait;

    #[ORM\Id, ORM\Column]
    public int $id;
}

#[ORM\Entity, ORM\Blameable(actorClass: MappingActor::class)]
final class ConflictingBlameableTarget implements BlameableInterface
{
    use BlameableTrait;

    #[ORM\Id, ORM\Column]
    public int $id;

    #[ORM\ManyToOne(targetEntity: AutomaticBlameable::class)]
    protected ?object $createdBy = null;
}

#[ORM\Entity, ORM\Blameable(actorClass: MappingActor::class)]
final class ConflictingBlameableColumn implements BlameableInterface
{
    use BlameableTrait;

    #[ORM\Id, ORM\Column]
    public int $id;

    #[ORM\Column(type: 'json', nullable: true)]
    protected ?object $updatedBy = null;
}

final class BlameableMappingTest extends TestCase
{
    private function manager(ArrayAdapter $cache, bool $underscore = false): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $config->setMetadataCache($cache);
        if ($underscore) {
            $config->setNamingStrategy(new ORM\UnderscoreNamingStrategy());
        }

        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '17']), $config);
    }

    public function testGeneratedAssociationsSurviveSerializedMetadataCache(): void
    {
        $cache = new ArrayAdapter(storeSerialized: true);
        foreach ([$this->manager($cache), $this->manager($cache)] as $em) {
            $metadata = $em->getClassMetadata(AutomaticBlameable::class);
            foreach (['createdBy', 'updatedBy'] as $field) {
                $mapping = $metadata->getAssociationMapping($field);
                self::assertInstanceOf(ORM\ManyToOneAssociationMapping::class, $mapping);
                self::assertSame(MappingActor::class, $mapping->targetEntity);
                self::assertSame($field . '_id', $mapping->joinColumns[0]->name);
                self::assertSame('SET NULL', $mapping->joinColumns[0]->onDelete);
                self::assertTrue($mapping->joinColumns[0]->nullable);
                self::assertSame([], $mapping->cascade);
            }
            self::assertFalse($em->getConnection()->isConnected());
        }
    }

    public function testGeneratedColumnsFollowNamingStrategy(): void
    {
        $metadata = $this->manager(new ArrayAdapter(), true)->getClassMetadata(AutomaticBlameable::class);
        self::assertSame('created_by_id', $metadata->getAssociationMapping('createdBy')->joinColumns[0]->name);
        self::assertSame('updated_by_id', $metadata->getAssociationMapping('updatedBy')->joinColumns[0]->name);
    }

    public function testCompatibleExplicitMappingIsPreserved(): void
    {
        $metadata = $this->manager(new ArrayAdapter())->getClassMetadata(ExplicitBlameable::class);
        $mapping = $metadata->getAssociationMapping('createdBy');
        self::assertSame('author_id', $mapping->joinColumns[0]->name);
        self::assertSame('RESTRICT', $mapping->joinColumns[0]->onDelete);
        self::assertSame(32, $mapping->batchSize);
        self::assertTrue($metadata->hasAssociation('updatedBy'));
    }

    public function testAssociationOverrideCustomizesGeneratedMapping(): void
    {
        $metadata = $this->manager(new ArrayAdapter())->getClassMetadata(OverriddenBlameable::class);
        $mapping = $metadata->getAssociationMapping('createdBy');
        self::assertSame('creator_id', $mapping->joinColumns[0]->name);
        self::assertSame('RESTRICT', $mapping->joinColumns[0]->onDelete);
        self::assertSame(MappingActor::class, $mapping->targetEntity);
    }

    public static function invalidMappings(): array
    {
        return [
            [MissingBlameableContract::class, 'requires BlameableInterface'],
            [BlameableSuperclass::class, 'must be applied to a concrete Entity'],
            [BlameableEmbeddable::class, 'must be applied to a concrete Entity'],
            [NonEntityBlameableActor::class, 'actorClass must be a concrete Entity'],
            [ConflictingBlameableTarget::class, 'must be ManyToOne targeting actorClass'],
            [ConflictingBlameableColumn::class, 'must be a ManyToOne association'],
        ];
    }

    #[DataProvider('invalidMappings')]
    public function testInvalidMappingsFailExplicitly(string $class, string $message): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage($message);
        $this->manager(new ArrayAdapter())->getClassMetadata($class);
    }
}
