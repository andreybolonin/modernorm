<?php

declare(strict_types=1);

namespace ModernORMRelease;

require $argv[1] ?? throw new \RuntimeException('Pass the consumer vendor/autoload.php path.');

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use ModernORM\Cache\CacheWritePolicy;
use ModernORM\Cache\Logging\StatisticsCacheLogger;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;
use ModernORM\Tools\SchemaTool;

#[ORM\Entity]
#[ORM\Table(name: 'target')]
#[ORM\Cacheable(ttl: 60, writePolicy: CacheWritePolicy::INVALIDATE)]
class Target
{
    #[ORM\Id] #[ORM\Column(type: 'integer')] public int $id;
    #[ORM\Column(unique: true)] public string $name;
}
#[ORM\Entity]
#[ORM\Table(name: 'owner')]
class Owner
{
    #[ORM\Id] #[ORM\Column(type: 'integer')] public int $id;
    #[ORM\ManyToOne(targetEntity: Target::class)]
    #[ORM\JoinColumn(name: 'target_id', referencedColumnName: 'id', nullable: false)]
    public Target $target;
}

$withCache = in_array('--with-cache', $argv, true);
if ($withCache !== class_exists(\Symfony\Component\Cache\Adapter\ArrayAdapter::class)) {
    throw new \RuntimeException('Unexpected optional cache dependency state in consumer.');
}
if (class_exists(\Symfony\Component\Console\Application::class) || interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
    throw new \RuntimeException('The consumer must not depend on Console or Doctrine ORM.');
}
$statistics = new Statistics(true);
$dbal = new DbalConfiguration();
$dbal->setMiddlewares([new Middleware($statistics)]);
$db = DriverManager::getConnection([
    'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1', 'port' => getenv('PGPORT') ?: 5432,
    'user' => getenv('PGUSER') ?: 'postgres', 'password' => getenv('PGPASSWORD') ?: 'postgres',
    'dbname' => getenv('PGDATABASE') ?: 'modernorm_test', 'serverVersion' => '17',
], $dbal);
$config = new Configuration();
$config->setMetadataDriverImpl(new AttributeDriver([]));
$config->setLazyFetchBatchSize(2);
$logger = new StatisticsCacheLogger();
if ($withCache) {
    $config->setSecondLevelCache(new \Symfony\Component\Cache\Adapter\ArrayAdapter(), 'consumer', $logger);
}
$em = new EntityManager($db, $config);
$schema = 'modernorm_consumer_'.bin2hex(random_bytes(6));
$metadata = [];
foreach ([Target::class, Owner::class] as $class) {
    $meta = $em->getClassMetadata($class);
    $meta->setPrimaryTable(['name' => $meta->getTableName(), 'schema' => $schema]);
    $metadata[] = $meta;
}
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};
try {
    (new SchemaTool($em))->createSchema($metadata);
    for ($id = 1; $id <= 5; ++$id) {
        $target = new Target();
        $target->id = $id;
        $target->name = 'target '.$id;
        $owner = new Owner();
        $owner->id = $id;
        $owner->target = $target;
        $em->persist($target);
        $em->persist($owner);
    }
    $em->flush();
    $em->clear();
    $owners = $em->getRepository(Owner::class)->findBy([], ['id' => 'ASC']);
    $statistics->reset();
    $names = array_map(static fn (Owner $owner): string => $owner->target->name, $owners);
    $batchQueries = $statistics->snapshot()->sqlCount;
    $check($batchQueries === 3 && count($names) === 5, 'Five lazy references at batch size two must use three SELECTs.');
    $check($em->find(Target::class, 1) === $owners[0]->target, 'Identity Map mismatch.');
    $owners[0]->target->name = 'updated';
    $em->flush();
    $em->clear();
    $check($em->find(Target::class, 1)->name === 'updated', 'UPDATE or L2 invalidation failed.');
    if ($withCache) {
        $em->clear();
        $statistics->reset();
        $logger->reset();
        $check($em->find(Target::class, 1)->name === 'updated', 'Cached row has stale state.');
        $check($statistics->snapshot()->sqlCount === 0 && $logger->getHitCount() === 1, 'Warm L2 lookup must avoid SQL.');
    }
    foreach ($em->getRepository(Owner::class)->findAll() as $owner) {
        $em->remove($owner);
    }
    $em->flush();
    foreach ($em->getRepository(Target::class)->findAll() as $target) {
        $em->remove($target);
    }
    $em->flush();
    $em->clear();
    $check($em->getRepository(Target::class)->count([]) === 0, 'DELETE failed.');
    foreach ([100, 101] as $id) {
        $target = new Target();
        $target->id = $id;
        $target->name = 'duplicate';
        $em->persist($target);
    }
    try {
        $em->flush();
        throw new \RuntimeException('Expected SQL UNIQUE failure.');
    } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
    }
    $check(!$em->isOpen(), 'Failed flush must close the EntityManager.');
    $check((int) $db->fetchOne('SELECT COUNT(*) FROM '.$schema.'.target') === 0, 'Failed flush did not roll back.');
    $recovered = new EntityManager($db, $config);
    foreach ([Target::class, Owner::class] as $class) {
        $meta = $recovered->getClassMetadata($class);
        $meta->setPrimaryTable(['name' => $meta->getTableName(), 'schema' => $schema]);
    }
    $check($recovered->getRepository(Target::class)->count([]) === 0, 'Recovery after failed flush failed.');
    echo json_encode(['php' => PHP_VERSION, 'postgresql' => $db->fetchOne('SHOW server_version'), 'cache' => $withCache, 'batch_selects' => $batchQueries, 'crud' => true, 'rollback_recovery' => true], JSON_THROW_ON_ERROR)."\n";
} finally {
    while ($db->isTransactionActive()) {
        $db->rollBack();
    }
    $db->executeStatement('DROP SCHEMA IF EXISTS '.$db->quoteIdentifier($schema).' CASCADE');
    $db->close();
}
