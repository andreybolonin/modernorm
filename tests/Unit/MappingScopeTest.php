<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Event\LoadClassMetadataEventArgs;
use ModernORM\Events;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Exception\MappingException;
use ModernORM\Query\ResultSetMapping;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\InheritanceType('SINGLE_TABLE')]
class UnsupportedSingleTable
{
    #[ORM\Id, ORM\Column] public int $id;
}

#[\ModernORM\Mapping\Entity, \ModernORM\Mapping\InheritanceType('JOINED')]
class UnsupportedJoined
{
    #[ORM\Id, ORM\Column] public int $id;
}

#[ORM\Entity, ORM\DiscriminatorColumn(name: 'kind')]
class UnsupportedDiscriminatorColumn
{
    #[ORM\Id, ORM\Column] public int $id;
}

#[ORM\Entity, ORM\DiscriminatorMap(['self' => UnsupportedDiscriminatorMap::class])]
class UnsupportedDiscriminatorMap
{
    #[ORM\Id, ORM\Column] public int $id;
}

#[ORM\Entity]
class ScopeEntity
{
    #[ORM\Id, ORM\Column] public int $id;
}

#[ORM\Entity]
class UnsupportedEntityChild extends ScopeEntity
{
}

#[ORM\Entity]
abstract class UnsupportedAbstractEntity
{
    #[ORM\Id, ORM\Column] public int $id;
}

#[ORM\MappedSuperclass, ORM\InheritanceType('JOINED')]
abstract class UnsupportedPolymorphicSuperclass
{
    #[ORM\Id, ORM\Column] public int $id;
}

final class MappingScopeTest extends TestCase
{
    private function manager(?ArrayAdapter $cache = null): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        if ($cache !== null) {
            $config->setMetadataCache($cache);
        }
        return new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'serverVersion' => '17',
        ]), $config);
    }

    public static function unsupportedMappings(): array
    {
        return [
            'single-table' => [UnsupportedSingleTable::class],
            'joined' => [UnsupportedJoined::class],
            'standalone-discriminator-column' => [UnsupportedDiscriminatorColumn::class],
            'standalone-discriminator-map' => [UnsupportedDiscriminatorMap::class],
            'entity-extends-entity-without-strategy' => [UnsupportedEntityChild::class],
            'abstract-entity' => [UnsupportedAbstractEntity::class],
            'polymorphic-mapped-superclass' => [UnsupportedPolymorphicSuperclass::class],
        ];
    }

    #[DataProvider('unsupportedMappings')]
    public function testUnsupportedEntityMappingFailsWithoutDatabaseConnection(string $class): void
    {
        $em = $this->manager();
        try {
            $em->getClassMetadata($class);
            self::fail('Expected unsupported inheritance mapping');
        } catch (MappingException $exception) {
            self::assertStringContainsString('Entity inheritance mapping is not supported', $exception->getMessage());
            self::assertStringContainsString('MappedSuperclass', $exception->getMessage());
        }
        self::assertFalse($em->getConnection()->isConnected());
    }

    public function testNonAttributeDriverIsRejectedAtConfiguration(): void
    {
        $config = new Configuration();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Attributes mapping only');
        $config->setMetadataDriverImpl($this->createMock(MappingDriver::class));
    }

    public function testMixedDriverChainIsRejectedEvenWithAttributeDriverInside(): void
    {
        $chain = new MappingDriverChain();
        $chain->addDriver(new AttributeDriver([]), __NAMESPACE__);
        $this->expectException(\InvalidArgumentException::class);
        (new Configuration())->setMetadataDriverImpl($chain);
    }

    public function testMetadataListenerCannotReintroduceEntityInheritance(): void
    {
        $em = $this->manager();
        $em->getEventManager()->addEventListener([Events::loadClassMetadata], new class () {
            public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
            {
                $args->getClassMetadata()->inheritanceType = ClassMetadata::INHERITANCE_TYPE_JOINED;
            }
        });
        $this->expectException(MappingException::class);
        $em->getClassMetadata(ScopeEntity::class);
    }

    public function testCachedInheritanceMetadataCannotBypassValidation(): void
    {
        $cache = new ArrayAdapter();
        $first = $this->manager($cache);
        $metadata = clone $first->getClassMetadata(ScopeEntity::class);
        $metadata->inheritanceType = ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE;
        $keys = array_keys($cache->getValues());
        self::assertCount(1, $keys);
        $cache->save($cache->getItem($keys[0])->set($metadata));
        $next = $this->manager($cache);
        try {
            $next->getClassMetadata(ScopeEntity::class);
            self::fail('Expected rejection of old inheritance metadata');
        } catch (MappingException $exception) {
            self::assertStringContainsString('Entity inheritance mapping is not supported', $exception->getMessage());
        }
        self::assertFalse($next->getConnection()->isConnected());
    }

    public function testDiscriminatorResultMappingCannotReintroducePolymorphicHydration(): void
    {
        $mapping = new ResultSetMapping();
        $mapping->addEntityResult(ScopeEntity::class, 's');
        $this->expectException(MappingException::class);
        $mapping->setDiscriminatorColumn('s', 'kind');
    }

    public function testDirectlyModifiedDiscriminatorMappingIsRejectedBySqlQuery(): void
    {
        $mapping = new ResultSetMapping();
        $mapping->addEntityResult(ScopeEntity::class, 's');
        $mapping->discriminatorColumns['s'] = 'kind';
        $this->expectException(MappingException::class);
        $this->manager()->createQuery('SELECT id, kind FROM scope_entities', $mapping);
    }

    public function testMetadataSetterRejectsInheritanceStrategy(): void
    {
        $metadata = new ClassMetadata(ScopeEntity::class);
        $this->expectException(MappingException::class);
        $metadata->setInheritanceType(ClassMetadata::INHERITANCE_TYPE_JOINED);
    }
}
