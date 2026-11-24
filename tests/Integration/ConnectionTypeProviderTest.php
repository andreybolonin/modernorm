<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TypeRegistry;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_connection_types')]
class ConnectionTypeEntity
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id = 1;

    #[ORM\Column(type: 'scoped_string')]
    public string $value = 'hello';
}

final class ScopedStringType extends StringType
{
    public function __construct(private readonly string $prefix)
    {
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return $value === null ? null : $this->prefix . $value;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return $value === null ? null : substr($value, strlen($this->prefix));
    }
}

final class ConnectionTypeProviderTest extends TestCase
{
    public function testScopedTypesRoundTripThroughSchemaWritesAndQueries(): void
    {
        foreach (['first:', 'second:'] as $prefix) {
            $type = new ScopedStringType($prefix);
            $dbal = new DbalConfiguration();
            $dbal->setTypeProvider(new TypeRegistry(['scoped_string' => $type]));
            $connection = DriverManager::getConnection([
                'driver' => 'pdo_pgsql',
                'host' => getenv('PGHOST') ?: '127.0.0.1',
                'port' => getenv('PGPORT') ?: 5432,
                'user' => getenv('PGUSER') ?: 'postgres',
                'password' => getenv('PGPASSWORD') ?: 'postgres',
                'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
            ], $dbal);
            $config = new Configuration();
            $config->setMetadataDriverImpl(new AttributeDriver([]));
            $em = new EntityManager($connection, $config);
            $schema = new SchemaTool($em);
            $classes = [$em->getClassMetadata(ConnectionTypeEntity::class)];
            try {
                $schema->dropSchema($classes);
                $schema->createSchema($classes);
                $entity = new ConnectionTypeEntity();
                $em->persist($entity);
                $em->flush();
                self::assertSame($prefix . 'hello', $connection->fetchOne('SELECT value FROM modernorm_test_connection_types'));
                $em->clear();
                $entity = $em->find(ConnectionTypeEntity::class, 1);
                self::assertSame('hello', $entity->value);
                $entity->value = 'updated';
                $em->flush();
                $em->clear();
                $query = $em->createQueryBuilder()->fromEntity(ConnectionTypeEntity::class, 'r')
                    ->where('r.value = :value')->setParameter('value', 'updated', $type)->getQuery();
                self::assertSame('updated', $query->getSingleResult()->value);
                self::assertSame([], $schema->getUpdateSchemaSql($classes));
            } finally {
                $schema->dropSchema($classes);
                $connection->close();
            }
        }
    }
}
