<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Event\LoadClassMetadataEventArgs;
use ModernORM\Events;
use ModernORM\Exception\MappingException;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

require_once __DIR__ . '/../Fixtures/RemovedFetchModes.php';

final class FetchModeScopeTest extends TestCase
{
    private function manager(?ArrayAdapter $cache = null): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        if ($cache !== null) {
            $config->setMetadataCache($cache);
        }
        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '18']), $config);
    }
    public static function removedMappings(): iterable
    {
        foreach ([RemovedFetchOneToMany::class, RemovedFetchManyToMany::class, RemovedFetchManyToOne::class, RemovedFetchOneToOne::class, RemovedFetchOverride::class] as $class) {
            yield $class => [$class];
        }
    }
    #[DataProvider('removedMappings')]
    public function testRemovedAttributeModeIsRejected(string $class): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('invalid fetch mode');
        $this->manager()->getClassMetadata($class);
    }
    public static function mappingMethods(): iterable
    {
        foreach (['mapOneToMany', 'mapManyToMany', 'mapOneToOne', 'mapManyToOne'] as $method) {
            yield $method => [$method];
        }
    }
    #[DataProvider('mappingMethods')]
    public function testRemovedNumericModeIsRejectedInProgrammaticMappings(string $method): void
    {
        $metadata = new ORM\ClassMetadata(FetchModeSource::class);
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage("invalid fetch mode '4'");
        $metadata->$method(['fieldName' => 'targets', 'targetEntity' => FetchModeTarget::class, 'mappedBy' => 'owner', 'fetch' => 4]);
    }
    public function testListenerCannotRestoreRemovedMode(): void
    {
        $em = $this->manager();
        $em->getEventManager()->addEventListener([Events::loadClassMetadata], new class () {
            public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
            {
                $metadata = $event->getClassMetadata();
                if ($metadata->name === FetchModeSource::class) {
                    (new ReflectionProperty($metadata->associationMappings['targets'], 'fetch'))->setValue($metadata->associationMappings['targets'], 4);
                }
            }
        });
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage("invalid fetch mode '4'");
        $em->getClassMetadata(FetchModeSource::class);
    }
    public function testRemovedModeInCachedMetadataIsRejected(): void
    {
        $cache = new ArrayAdapter(storeSerialized: true);
        $em = $this->manager($cache);
        $metadata = $em->getClassMetadata(FetchModeSource::class);
        (new ReflectionProperty($metadata->associationMappings['targets'], 'fetch'))->setValue($metadata->associationMappings['targets'], 4);
        $key = (new ReflectionMethod($em->getMetadataFactory(), 'getCacheKey'))->invoke($em->getMetadataFactory(), FetchModeSource::class);
        $item = $cache->getItem($key);
        $item->set($metadata);
        $cache->save($item);
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage("invalid fetch mode '4'");
        $this->manager($cache)->getClassMetadata(FetchModeSource::class);
    }
    public function testLazyAndEagerSurviveMetadataCacheAndOverrides(): void
    {
        $cache = new ArrayAdapter(storeSerialized: true);
        $metadata = $this->manager($cache)->getClassMetadata(FetchModeSource::class);
        self::assertSame(ORM\ClassMetadata::FETCH_LAZY, $metadata->associationMappings['targets']->fetch);
        $restored = $this->manager($cache)->getClassMetadata(FetchModeSource::class);
        $restored->setAssociationOverride('targets', ['fetch' => ORM\ClassMetadata::FETCH_EAGER]);
        self::assertSame(ORM\ClassMetadata::FETCH_EAGER, $restored->associationMappings['targets']->fetch);
        $restored->assertSupportedMapping();
    }
}
