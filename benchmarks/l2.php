<?php

declare(strict_types=1);

namespace ModernORML2Benchmark;

require dirname(__DIR__).'/vendor/autoload.php';

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
use Symfony\Component\Cache\Adapter\RedisAdapter;

#[ORM\Entity, ORM\Table(name: 'target'), ORM\Cacheable(ttl: 3600, writePolicy: CacheWritePolicy::INVALIDATE)]
class Target
{
    #[ORM\Id, ORM\Column(type: 'integer')] public int $id;
    #[ORM\Column] public string $name;
    #[ORM\Column(type: 'integer')] public int $amount;
}

#[ORM\Entity, ORM\Table(name: 'owner')]
class Owner
{
    #[ORM\Id, ORM\Column(type: 'integer')] public int $id;
    #[ORM\ManyToOne(targetEntity: Target::class, batchSize: 32)]
    #[ORM\JoinColumn(name: 'target_id', referencedColumnName: 'id', nullable: false)] public Target $target;
}

function option(string $name, int $default, int $minimum, int $maximum): int
{
    $raw = getenv($name);
    if ($raw === false) { return $default; }
    $value = filter_var($raw, FILTER_VALIDATE_INT);
    if ($value === false || $value < $minimum || $value > $maximum) {
        throw new \InvalidArgumentException("$name must be an integer from $minimum to $maximum.");
    }
    return $value;
}

function median(array $values): float
{
    sort($values); $middle = intdiv(count($values), 2);
    return count($values) % 2 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
}

$rows = option('MODERNORM_BENCH_ROWS', 100, 2, 5000);
$rounds = option('MODERNORM_BENCH_ROUNDS', 5, 3, 25);
$dsn = getenv('MODERNORM_BENCH_REDIS_DSN') ?: getenv('MODERNORM_TEST_REDIS_DSN');
if (!$dsn) { throw new \InvalidArgumentException('Set MODERNORM_BENCH_REDIS_DSN to a dedicated Redis service.'); }
$statistics = new Statistics(true);
$cacheLogger = new StatisticsCacheLogger();
$dbal = new DbalConfiguration(); $dbal->setMiddlewares([new Middleware($statistics)]);
$db = DriverManager::getConnection([
    'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1', 'port' => getenv('PGPORT') ?: 5432,
    'user' => getenv('PGUSER') ?: 'postgres', 'password' => getenv('PGPASSWORD') ?: 'postgres',
    'dbname' => getenv('PGDATABASE') ?: 'modernorm_test', 'serverVersion' => '17',
], $dbal);
$namespace = 'modernorm_l2_bench_'.bin2hex(random_bytes(6));
$schema = $db->quoteIdentifier($namespace);
$client = RedisAdapter::createConnection($dsn, ['timeout' => 1, 'read_timeout' => 1, 'retry_interval' => 0]);
$pool = new RedisAdapter($client, $namespace, 0);
$create = static function (bool $enabled) use ($db, $namespace, $pool, $cacheLogger): EntityManager {
    $config = new Configuration(); $config->setMetadataDriverImpl(new AttributeDriver([]));
    if ($enabled) { $config->setSecondLevelCache($pool, namespace: $namespace, logger: $cacheLogger); }
    $em = new EntityManager($db, $config);
    foreach ([Target::class, Owner::class] as $entity) {
        $meta = $em->getClassMetadata($entity);
        $meta->setPrimaryTable(['name' => $meta->getTableName(), 'schema' => $namespace]);
    }
    return $em;
};
$prime = static function (int $count) use ($create): void {
    $em = $create(true);
    try { for ($id = 1; $id <= $count; ++$id) { $em->find(Target::class, $id); } }
    finally { $em->close(); }
};
$cases = [];
foreach (['by_id', 'many_to_one'] as $operation) {
    foreach (['disabled', 'cold', 'warm', 'mixed'] as $mode) { $cases[] = [$operation, $mode]; }
}
$cases[] = ['update', 'disabled']; $cases[] = ['update', 'invalidate'];
$checksum = 0;
for ($id = 1; $id <= $rows; ++$id) { $checksum += strlen('target '.$id); }

$measure = static function (string $operation, string $mode) use (
    $db, $schema, $pool, $create, $prime, $rows, $checksum, $statistics, $cacheLogger,
): array {
    if (!$pool->clear()) { throw new \RuntimeException('Unable to clear the owned benchmark namespace.'); }
    $db->executeStatement("UPDATE $schema.target SET name = 'target ' || id, amount = 0");
    $enabled = $mode !== 'disabled';
    $warm = $mode === 'warm' || $mode === 'invalidate' ? $rows : ($mode === 'mixed' ? intdiv($rows, 2) : 0);
    if ($warm > 0) { $prime($warm); }
    $em = $create($enabled);
    $entities = $operation === 'update' ? $em->getRepository(Target::class)->findBy([], ['id' => 'ASC']) : [];
    gc_collect_cycles(); memory_reset_peak_usage(); $heap = memory_get_usage();
    $statistics->reset(); $cacheLogger->reset(); $start = hrtime(true);
    try {
        $actualChecksum = 0;
        if ($operation === 'by_id') {
            for ($id = 1; $id <= $rows; ++$id) { $actualChecksum += strlen($em->find(Target::class, $id)->name); }
        } elseif ($operation === 'many_to_one') {
            $owners = $em->getRepository(Owner::class)->findBy([], ['id' => 'ASC']);
            foreach ($owners as $owner) { $actualChecksum += strlen($owner->target->name); }
        } else {
            foreach ($entities as $entity) { $entity->name = 'updated'; $entity->amount = 1; }
            $em->flush();
        }
        $elapsed = (hrtime(true) - $start) / 1e6;
        $sample = ['wall_ms' => $elapsed, 'sql_statements' => $statistics->snapshot()->sqlCount,
            'hits' => $cacheLogger->getHitCount(), 'misses' => $cacheLogger->getMissCount(),
            'puts' => $cacheLogger->getPutCount(), 'evictions' => $cacheLogger->getEvictionCount(),
            'peak_heap_delta_bytes' => memory_get_peak_usage() - $heap, 'retained_heap_delta_bytes' => memory_get_usage() - $heap];
        // All result/cache assertions and verification reads are outside the measurement.
        $expectedSql = $operation === 'update' ? $rows : ($operation === 'by_id'
            ? $rows - ($enabled ? $warm : 0)
            : 1 + (int) ceil($rows / 32) - ($enabled ? intdiv($warm, 32) : 0));
        if ($operation === 'many_to_one' && $mode === 'warm') { $expectedSql = 1; }
        $expectedHits = $enabled && $operation !== 'update' ? $warm : 0;
        $expectedMisses = $enabled && $operation !== 'update' ? $rows - $warm : 0;
        if ($sample['sql_statements'] !== $expectedSql || $sample['hits'] !== $expectedHits
            || $sample['misses'] !== $expectedMisses || $sample['puts'] !== $expectedMisses
            || $sample['evictions'] !== ($operation === 'update' && $enabled ? 2 * $rows : 0)) {
            throw new \RuntimeException("Unexpected SQL/cache counts for $operation/$mode: ".json_encode($sample));
        }
        if ($operation !== 'update' && $actualChecksum !== $checksum) { throw new \RuntimeException('Read result mismatch.'); }
        if ($operation === 'update') {
            if ((int) $db->fetchOne("SELECT count(*) FROM $schema.target WHERE name = 'updated' AND amount = 1") !== $rows) {
                throw new \RuntimeException('UPDATE result mismatch.');
            }
            if ($enabled) {
                $verify = $create(true);
                try {
                    for ($id = 1; $id <= $rows; ++$id) {
                        if ($verify->find(Target::class, $id)->name !== 'updated') { throw new \RuntimeException('Stale cache after UPDATE.'); }
                    }
                    $verify->clear();
                    for ($id = 1; $id <= $rows; ++$id) {
                        if ($verify->find(Target::class, $id)->amount !== 1) { throw new \RuntimeException('Stale warm cache after UPDATE.'); }
                    }
                } finally { $verify->close(); }
            }
        }
        return $sample;
    } finally {
        while ($db->isTransactionActive()) { $db->rollBack(); }
        $em->close();
    }
};

try {
    $db->executeStatement('CREATE SCHEMA '.$schema);
    $db->executeStatement("CREATE TABLE $schema.target (id INTEGER PRIMARY KEY, name VARCHAR(255) NOT NULL, amount INTEGER NOT NULL)");
    $db->executeStatement("CREATE TABLE $schema.owner (id INTEGER PRIMARY KEY, target_id INTEGER NOT NULL REFERENCES $schema.target(id))");
    $db->executeStatement("INSERT INTO $schema.target SELECT id, 'target ' || id, 0 FROM generate_series(1, ?) id", [$rows]);
    $db->executeStatement("INSERT INTO $schema.owner SELECT id, id FROM generate_series(1, ?) id", [$rows]);
    foreach ($cases as [$operation, $mode]) { $measure($operation, $mode); }
    $samples = [];
    for ($round = 0; $round < $rounds; ++$round) {
        $offset = ($round * 3) % count($cases);
        foreach (array_merge(array_slice($cases, $offset), array_slice($cases, 0, $offset)) as [$operation, $mode]) {
            fwrite(STDERR, sprintf("L2 round=%d/%d %s/%s\n", $round + 1, $rounds, $operation, $mode));
            $samples[$operation][$mode][] = $measure($operation, $mode);
        }
    }
    $measurements = [];
    foreach ($samples as $operation => $modes) {
        foreach ($modes as $mode => $runs) {
            $times = array_column($runs, 'wall_ms');
            $measurements[] = ['operation' => $operation, 'mode' => $mode, 'median_ms' => median($times),
                'min_ms' => min($times), 'max_ms' => max($times),
                'median_peak_heap_delta_bytes' => median(array_column($runs, 'peak_heap_delta_bytes')), 'samples' => $runs];
        }
    }
    echo json_encode(['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'postgresql' => $db->fetchOne('SHOW server_version'),
        'redis' => $client->info('server')['redis_version'], 'dbal' => \Composer\InstalledVersions::getPrettyVersion('doctrine/dbal'),
        'symfony_cache' => \Composer\InstalledVersions::getPrettyVersion('symfony/cache'), 'rows' => $rows, 'rounds' => $rounds,
        'batch_size' => 32, 'mixed_warm_rows' => intdiv($rows, 2), 'measurements' => $measurements,
        'method' => 'One warm-up per case; measured case order rotates. Fresh EntityManager per sample, metadata initialized before timing, Statistics/cache counters enabled. Reads include hydration, exclude setup/cache priming. L2 mixed warms the first half of sequential IDs. UPDATE starts with all targets loaded, and all L2 entries primed when enabled; timing includes dirty checking, SQL, owned commit and synchronous pre/post invalidation. No refill in UPDATE timing. One shared local PostgreSQL connection and Redis client. Namespace reset and DB reset outside timing. PHP heap deltas, not RSS. No Doctrine comparison or latency thresholds.',
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} finally {
    while ($db->isTransactionActive()) { $db->rollBack(); }
    try { $db->executeStatement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE'); }
    finally { $db->close(); $pool->clear(); }
}
