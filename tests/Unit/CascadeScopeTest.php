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
use ModernORM\Mapping\Builder\ClassMetadataBuilder;
use ModernORM\Mapping\Driver\AttributeDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

require_once __DIR__ . '/../Fixtures/RemovedCascadeModes.php';

final class CascadeScopeTest extends TestCase
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
    public static function removedAttributes(): iterable
    {
        foreach (['OneToMany', 'ManyToMany', 'OneToOne', 'ManyToOne'] as $association) {
            foreach (['Refresh', 'Detach', 'AllRefresh'] as $option) {
                yield $association . $option => [__NAMESPACE__ . '\RemovedCascade' . $association . $option];
            }
        }
    }
    #[DataProvider('removedAttributes')]
    public function testRemovedAttributeCascadesAreRejected(string $class): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('invalid cascade options');
        $this->manager()->getClassMetadata($class);
    }
    public static function removedMappings(): iterable
    {
        foreach (['mapOneToMany', 'mapManyToMany', 'mapOneToOne', 'mapManyToOne'] as $method) {
            foreach ([['refresh'], ['DETACH'], ['all', 'refresh'], ['ALL', 'detach']] as $cascades) {
                yield $method . ':' . implode(',', $cascades) => [$method, $cascades];
            }
        }
    }
    #[DataProvider('removedMappings')]
    public function testProgrammaticMappingsRejectRemovedOptionsEvenWithAll(string $method, array $cascades): void
    {
        $metadata = new ORM\ClassMetadata(AllCascadeSource::class);
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('invalid cascade options');
        $metadata->$method(['fieldName' => 'targets', 'targetEntity' => CascadeModeTarget::class, 'mappedBy' => 'owner', 'cascade' => $cascades]);
    }
    public static function removedOptions(): iterable
    {
        yield ['refresh'];
        yield ['detach'];
    }
    #[DataProvider('removedOptions')]
    public function testListenerCannotRestoreRemovedCascade(string $option): void
    {
        $em = $this->manager();
        $em->getEventManager()->addEventListener([Events::loadClassMetadata], new class ($option) {
            public function __construct(private string $option)
            {
            }
            public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
            {
                $metadata = $event->getClassMetadata();
                if ($metadata->name === AllCascadeSource::class) {
                    $mapping = $metadata->associationMappings['targets'];
                    (new ReflectionProperty($mapping, 'cascade'))->setValue($mapping, [$this->option]);
                }
            }
        });
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('invalid cascade options');
        $em->getClassMetadata(AllCascadeSource::class);
    }
    #[DataProvider('removedOptions')]
    public function testCachedMetadataCannotRestoreRemovedCascade(string $option): void
    {
        $cache = new ArrayAdapter(storeSerialized: true);
        $em = $this->manager($cache);
        $metadata = $em->getClassMetadata(AllCascadeSource::class);
        $mapping = $metadata->associationMappings['targets'];
        (new ReflectionProperty($mapping, 'cascade'))->setValue($mapping, [$option]);
        $key = (new ReflectionMethod($em->getMetadataFactory(), 'getCacheKey'))->invoke($em->getMetadataFactory(), AllCascadeSource::class);
        $cache->save($cache->getItem($key)->set($metadata));
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('invalid cascade options');
        $this->manager($cache)->getClassMetadata(AllCascadeSource::class);
    }
    public function testAllMeansPersistAndRemoveIncludingBuilderAndSerializedCache(): void
    {
        $cache = new ArrayAdapter(storeSerialized: true);
        foreach ([$this->manager($cache), $this->manager($cache)] as $em) {
            $mapping = $em->getClassMetadata(AllCascadeSource::class)->associationMappings['targets'];
            self::assertSame(['remove', 'persist'], $mapping->cascade);
            self::assertTrue($mapping->isCascadePersist());
            self::assertTrue($mapping->isCascadeRemove());
        }
        $metadata = new ORM\ClassMetadata(AllCascadeSource::class);
        (new ClassMetadataBuilder($metadata))->createManyToMany('targets', CascadeModeTarget::class)->cascadeAll()->build();
        self::assertSame(['remove', 'persist'], $metadata->associationMappings['targets']->cascade);
    }
}
