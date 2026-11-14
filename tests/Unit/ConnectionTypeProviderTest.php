<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TypeRegistry;
use ModernORM\Cache\EntityCacheKey;
use ModernORM\Cache\ReadOnlyEntityHydrator;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Internal\Hydration\ObjectHydrator;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Query\EntityResultPlan;
use ModernORM\Query\ResultSetMapping;
use ModernORM\Query\ResultSetMappingBuilder;
use ModernORM\Tools\SchemaValidator;
use ModernORM\Utility\PersisterHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity]
class ConnectionTypeRecord
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;

    #[ORM\Column(type: 'connection_only')]
    public string $value;
}

final class ConnectionStringType extends StringType
{
    public function __construct(private readonly string $prefix)
    {
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return $value === null ? null : $this->prefix . $value;
    }

    public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
    {
        return $this->prefix . '(' . $sqlExpr . ')';
    }

    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
    {
        return $this->prefix . '(' . $sqlExpr . ')';
    }
}

final class ConnectionTypeProviderTest extends TestCase
{
    private function manager(string $prefix, ?ArrayAdapter $cache = null): EntityManager
    {
        $dbal = new DbalConfiguration();
        $dbal->setTypeProvider(new TypeRegistry(['connection_only' => new ConnectionStringType($prefix)]));
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $config->setQueryCache($cache ?? new ArrayAdapter());
        return new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'serverVersion' => '17',
        ], $dbal), $config);
    }

    public function testSqlPlansWithSharedCacheUseTheReceivingConnectionsType(): void
    {
        $cache = new ArrayAdapter();
        foreach (['first', 'second', 'first'] as $prefix) {
            $em = $this->manager($prefix, $cache);
            $plan = EntityResultPlan::forEntity($em, ConnectionTypeRecord::class, 'r');
            self::assertStringContainsString($prefix . '(r.value)', implode(', ', $plan->select));
            $builder = new ResultSetMappingBuilder($em);
            $builder->addRootEntityFromClassMetadata(ConnectionTypeRecord::class, 'r');
            self::assertStringContainsString($prefix . '(r.value)', $builder->generateSelectClause());
        }
    }

    public function testHydrationAndL2ConversionUseConnectionTypes(): void
    {
        foreach (['first', 'second'] as $prefix) {
            $em = $this->manager($prefix);
            $mapping = new ResultSetMapping();
            $mapping->addEntityResult(ConnectionTypeRecord::class, 'r');
            $mapping->addFieldResult('r', 'id', 'id');
            $mapping->addFieldResult('r', 'value', 'value');
            $result = $this->createMock(Result::class);
            $result->method('fetchAssociative')->willReturnOnConsecutiveCalls(['id' => '1', 'value' => 'raw'], false);
            $entities = (new ObjectHydrator($em))->hydrateAll($result, $mapping);
            self::assertSame($prefix . 'raw', $entities[0]->value);

            $metadata = $em->getClassMetadata(ConnectionTypeRecord::class);
            $hydrator = new ReadOnlyEntityHydrator($em);
            $entry = $hydrator->fromDatabaseRow($metadata, ['id' => '1', 'value' => 'raw']);
            self::assertSame(['id' => 1, 'value' => $prefix . 'raw'], $hydrator->getData(
                $metadata,
                new EntityCacheKey(ConnectionTypeRecord::class, ['id' => 1]),
                $entry,
            ));
        }
    }

    public function testValidationPersisterAndArrayParametersUseConnectionTypes(): void
    {
        $em = $this->manager('local');
        $metadata = $em->getClassMetadata(ConnectionTypeRecord::class);
        self::assertSame([], (new SchemaValidator($em))->validateClass($metadata));
        self::assertSame([ArrayParameterType::STRING], PersisterHelper::inferParameterTypes('value', ['x'], $metadata, $em));
        $persister = $em->getUnitOfWork()->getEntityPersister(ConnectionTypeRecord::class);
        self::assertStringContainsString('local(?)', $persister->getSelectSQL(['value' => 'x']));
    }

    public function testQueryBuilderPreservesUnregisteredTypeInstances(): void
    {
        $em = $this->manager('local');
        $type = new ConnectionStringType('unregistered');
        $query = $em->createQueryBuilder()->fromEntity(ConnectionTypeRecord::class, 'r')
            ->where('r.value = :value')->setParameter('value', 'x', $type)->getQuery();
        self::assertSame($type, $query->getParameter('value')->getType());
    }
}
