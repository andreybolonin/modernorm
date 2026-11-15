<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Closure;
use ReflectionProperty;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\Driver\AttributeDriver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity]
final class RuntimeRestoredMetadataEntity
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;
    #[ORM\Column]
    public string $name;
    public string $extra;
    public ?self $owner = null;
}

final class RuntimeNamingStrategy extends ORM\DefaultNamingStrategy
{
    /** @param Closure(): string $prefix */
    public function __construct(private readonly Closure $prefix)
    {
    }
    public function propertyToColumnName(string $propertyName, string $className): string
    {
        return ($this->prefix)() . $propertyName;
    }
    public function joinColumnName(string $propertyName, string $className): string
    {
        return ($this->prefix)() . 'fk_' . $propertyName;
    }
    public function referenceColumnName(): string
    {
        return ($this->prefix)() . 'id';
    }
}

final class RuntimeTypedFieldMapper implements ORM\TypedFieldMapper
{
    public function __construct(private readonly Closure $mapping)
    {
    }
    public function validateAndComplete(array $mapping, ReflectionProperty $field): array
    {
        return ($this->mapping)($mapping, $field);
    }
}

final class CountingMetadataDriver extends AttributeDriver
{
    public int $loads = 0;
    public function loadMetadataForClass(string $className, \Doctrine\Persistence\Mapping\ClassMetadata $metadata): void
    {
        ++$this->loads;
        parent::loadMetadataForClass($className, $metadata);
    }
}

final class MetadataRuntimeServicesTest extends TestCase
{
    public function testStandaloneWakeupRestoresDefaultMappingServices(): void
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '17']), $config);
        $metadata = unserialize(serialize($em->getClassMetadata(RuntimeRestoredMetadataEntity::class)));
        self::assertInstanceOf(ClassMetadata::class, $metadata);
        $metadata->wakeupReflection(new RuntimeReflectionService());
        $metadata->mapField(['fieldName' => 'extra']);
        self::assertSame('string', $metadata->getTypeOfField('extra'));
        self::assertSame('extra', $metadata->getColumnName('extra'));
        $metadata->mapManyToOne(['fieldName' => 'owner', 'targetEntity' => RuntimeRestoredMetadataEntity::class]);
        $association = $metadata->getAssociationMapping('owner');
        self::assertInstanceOf(ORM\ManyToOneAssociationMapping::class, $association);
        self::assertSame('owner_id', $association->joinColumns[0]->name);
        $metadata->setAssociationOverride('owner', ['joinColumns' => [['name' => 'changed_owner', 'referencedColumnName' => 'id']]]);
        $overridden = $metadata->getAssociationMapping('owner');
        self::assertInstanceOf(ORM\ManyToOneAssociationMapping::class, $overridden);
        self::assertSame('changed_owner', $overridden->joinColumns[0]->name);
        self::assertFalse($em->getConnection()->isConnected());
    }

    public function testWarmMetadataCacheRebindsCustomRuntimeServicesWithoutSerializingThem(): void
    {
        $pool = new ArrayAdapter(0, true);
        $driver = new CountingMetadataDriver([]);
        $validated = [];
        $config = new Configuration();
        $config->setMetadataDriverImpl($driver);
        $config->setMetadataCache($pool);
        $config->setNamingStrategy(new RuntimeNamingStrategy(static fn (): string => 'runtime_'));
        $config->setTypedFieldMapper(new RuntimeTypedFieldMapper(static function (array $mapping, ReflectionProperty $field) use (&$validated): array {
            $validated[] = $field->getName();
            return (new ORM\DefaultTypedFieldMapper(['string' => 'text']))->validateAndComplete($mapping, $field);
        }));
        $first = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '17']), $config);
        $original = $first->getClassMetadata(RuntimeRestoredMetadataEntity::class);
        $loads = $driver->loads;
        self::assertSame('text', $original->getTypeOfField('name'));
        self::assertSame('runtime_name', $original->getColumnName('name'));
        $warm = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '17']), $config);
        $metadata = $warm->getClassMetadata(RuntimeRestoredMetadataEntity::class);
        self::assertNotSame($original, $metadata, 'The pool must actually serialize and restore metadata.');
        self::assertSame($loads, $driver->loads, 'The warm path must not reload mapping attributes.');
        $metadata->mapField(['fieldName' => 'extra']);
        self::assertSame('text', $metadata->getTypeOfField('extra'));
        self::assertSame('runtime_extra', $metadata->getColumnName('extra'));
        self::assertSame('extra', $validated[array_key_last($validated)]);
        $metadata->mapManyToOne(['fieldName' => 'owner', 'targetEntity' => RuntimeRestoredMetadataEntity::class]);
        $association = $metadata->getAssociationMapping('owner');
        self::assertInstanceOf(ORM\ManyToOneAssociationMapping::class, $association);
        self::assertSame('runtime_fk_owner', $association->joinColumns[0]->name);
        self::assertSame('runtime_id', $association->joinColumns[0]->referencedColumnName);
        $metadata->setAssociationOverride('owner', ['joinColumns' => [['name' => 'changed_owner', 'referencedColumnName' => 'runtime_id']]]);
        $overridden = $metadata->getAssociationMapping('owner');
        self::assertInstanceOf(ORM\ManyToOneAssociationMapping::class, $overridden);
        self::assertSame('changed_owner', $overridden->joinColumns[0]->name);
        // The configured services contain closures and must stay out of cached metadata payloads.
        self::assertInstanceOf(ClassMetadata::class, unserialize(serialize($metadata)));
        $manual = unserialize(serialize($original));
        self::assertInstanceOf(ClassMetadata::class, $manual);
        $manual->restoreMappingServices($config->getNamingStrategy(), $config->getTypedFieldMapper());
        $manual->wakeupReflection(new RuntimeReflectionService());
        $manual->mapField(['fieldName' => 'extra']);
        self::assertSame('runtime_extra', $manual->getColumnName('extra'));
        self::assertSame('text', $manual->getTypeOfField('extra'));
        self::assertFalse($warm->getConnection()->isConnected());
    }

}
