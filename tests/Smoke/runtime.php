<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Configuration as DBALConfiguration;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Tools\SchemaTool;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;

#[ORM\MappedSuperclass]
abstract class RuntimeEntityBase
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_runtime')]
final class RuntimeEntity extends RuntimeEntityBase
{
    #[ORM\Column]
    public string $name = 'runtime';
}

if (class_exists(Symfony\Component\VarExporter\Hydrator::class)
    || class_exists(Symfony\Component\Console\Application::class)) {
    throw new RuntimeException('Run this smoke test after composer install --no-dev.');
}

$config = new Configuration();
$config->setMetadataDriverImpl(new AttributeDriver([]));
$statistics = new Statistics(enabled: true);
$dbalConfig = new DBALConfiguration();
$dbalConfig->setMiddlewares([new Middleware($statistics)]);
$connection = DriverManager::getConnection([
    'driver' => 'pdo_pgsql',
    'host' => getenv('PGHOST') ?: '127.0.0.1',
    'port' => getenv('PGPORT') ?: 5432,
    'user' => getenv('PGUSER') ?: 'postgres',
    'password' => getenv('PGPASSWORD') ?: 'postgres',
    'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
], $dbalConfig);
$em = new EntityManager($connection, $config);
try {
    (new SchemaTool($em))->createSchema([$em->getClassMetadata(RuntimeEntity::class)]);
    $statistics->reset();
    $entity = new RuntimeEntity();
    $em->persist($entity);
    $em->flush();
    $id = $entity->id;
    $em->clear();
    $reference = $em->getReference(RuntimeEntity::class, $id);
    if (! $em->isUninitializedObject($reference) || $reference->name !== 'runtime') {
        throw new RuntimeException('Native lazy loading failed.');
    }
    if ($em->find(RuntimeEntity::class, $id) !== $reference) {
        throw new RuntimeException('Identity Map failed.');
    }
    $queried = $em->createQueryBuilder()->fromEntity(RuntimeEntity::class, 'r')
        ->where('r.id = :id')->setParameter('id', $id)->getQuery()->getSingleResult();
    if ($queried !== $reference) {
        throw new RuntimeException('SQL entity query broke Identity Map.');
    }
    $snapshot = $statistics->snapshot();
    if ($em->getStatistics() !== $statistics || $snapshot->flushCount !== 1
        || $snapshot->sqlCount < 1 || $snapshot->sqlErrors !== 0 || $snapshot->flushErrors !== 0) {
        throw new RuntimeException('Runtime statistics failed.');
    }
    echo "Runtime smoke with statistics passed without console or var-exporter.\n";
} finally {
    $connection->executeStatement('DROP TABLE IF EXISTS modernorm_test_runtime');
    $connection->close();
}
