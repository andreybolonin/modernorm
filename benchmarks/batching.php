<?php

declare(strict_types=1);

namespace ModernORMBenchmark;

require dirname(__DIR__).'/vendor/autoload.php';

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;
use ModernORM\Tools\SchemaTool;

#[ORM\Entity]
#[ORM\Table(name: 'target')]
class Target
{
    #[ORM\Id] #[ORM\Column(type: 'integer')] public int $id;
    #[ORM\Column] public string $name;
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

$rows = max(1, min(5000, (int) (getenv('MODERNORM_BENCH_ROWS') ?: 100)));
$statistics = new Statistics(true);
$dbal = new DbalConfiguration(); $dbal->setMiddlewares([new Middleware($statistics)]);
$db = DriverManager::getConnection([
    'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1', 'port' => getenv('PGPORT') ?: 5432,
    'user' => getenv('PGUSER') ?: 'postgres', 'password' => getenv('PGPASSWORD') ?: 'postgres',
    'dbname' => getenv('PGDATABASE') ?: 'modernorm_test', 'serverVersion' => '17',
], $dbal);
$schema = 'modernorm_bench_'.bin2hex(random_bytes(6));
$create = static function (int $size) use ($schema, $db): EntityManager {
    $config = new Configuration(); $config->setMetadataDriverImpl(new AttributeDriver([])); $config->setLazyFetchBatchSize($size);
    $em = new EntityManager($db, $config);
    foreach ([Target::class, Owner::class] as $class) {
        $meta = $em->getClassMetadata($class); $meta->setPrimaryTable(['name' => $meta->getTableName(), 'schema' => $schema]);
    }
    return $em;
};
try {
    $seed = $create(1);
    (new SchemaTool($seed))->createSchema([$seed->getClassMetadata(Target::class), $seed->getClassMetadata(Owner::class)]);
    for ($id = 1; $id <= $rows; ++$id) {
        $target = new Target(); $target->id = $id; $target->name = 'target '.$id;
        $owner = new Owner(); $owner->id = $id; $owner->target = $target;
        $seed->persist($target); $seed->persist($owner);
    }
    $seed->flush(); $seed->close(); unset($seed, $target, $owner);
    $measurements = [];
    foreach ([1, 32] as $size) {
        $em = $create($size);
        gc_collect_cycles(); memory_reset_peak_usage(); $memory = memory_get_usage();
        $statistics->reset(); $start = hrtime(true);
        $owners = $em->getRepository(Owner::class)->findBy([], ['id' => 'ASC']);
        $checksum = 0;
        foreach ($owners as $owner) { $checksum += strlen($owner->target->name); }
        $elapsed = (hrtime(true) - $start) / 1_000_000;
        $count = $statistics->snapshot()->sqlCount;
        if ($count !== 1 + (int) ceil($rows / $size)) { throw new \RuntimeException('Unexpected batch SELECT count.'); }
        $measurements[] = ['batch_size' => $size, 'rows' => $rows, 'selects_including_root' => $count, 'wall_ms' => $elapsed, 'retained_bytes' => memory_get_usage() - $memory, 'peak_delta_bytes' => memory_get_peak_usage() - $memory, 'managed_entities' => array_sum(array_map('count', $em->getUnitOfWork()->getIdentityMap())), 'checksum' => $checksum];
        $em->close(); unset($owners, $owner, $em);
    }
    echo json_encode(['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'postgresql' => $db->fetchOne('SHOW server_version'), 'measurements' => $measurements, 'note' => 'One local run per batch size, includes hydration. Query counts are checked; latency/memory are observations, not a Doctrine comparison.'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} finally {
    while ($db->isTransactionActive()) { $db->rollBack(); }
    $db->executeStatement('DROP SCHEMA IF EXISTS '.$db->quoteIdentifier($schema).' CASCADE'); $db->close();
}
