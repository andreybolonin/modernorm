<?php

declare(strict_types=1);

namespace ModernORMBulkBenchmark;

require dirname(__DIR__).'/vendor/autoload.php';

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use ModernORM\Bulk\BulkPolicy;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;

#[ORM\Entity]
#[ORM\Table(name: 'record')]
class Record
{
    #[ORM\Id] #[ORM\Column(type: 'integer')] public int $id;
    #[ORM\Column] public string $label;
    #[ORM\Column(type: 'integer')] public int $amount;
}

function integerOption(string $name, int $default, int $min, int $max): int
{
    $value = getenv($name);
    if ($value === false) { return $default; }
    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    if ($parsed === false || $parsed < $min || $parsed > $max) {
        throw new \InvalidArgumentException("$name must be an integer between $min and $max.");
    }
    return $parsed;
}

function median(array $values): float
{
    sort($values);
    $middle = intdiv(count($values), 2);
    return count($values) % 2 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
}

$sizes = array_map(static function (string $value): int {
    $parsed = filter_var(trim($value), FILTER_VALIDATE_INT);
    if ($parsed === false || $parsed < 1 || $parsed > 100000) {
        throw new \InvalidArgumentException('MODERNORM_BENCH_SIZES must contain comma-separated integers from 1 to 100000.');
    }
    return $parsed;
}, explode(',', getenv('MODERNORM_BENCH_SIZES') ?: '1000,10000,100000'));
$rounds = integerOption('MODERNORM_BENCH_ROUNDS', 3, 3, 25);
$chunkSize = integerOption('MODERNORM_BENCH_CHUNK_SIZE', 1000, 1, 10000);
$contextSize = integerOption('MODERNORM_BENCH_CONTEXT_SIZE', 1000, 1, 10000);
$statistics = new Statistics(true);
$dbal = new DbalConfiguration();
$dbal->setMiddlewares([new Middleware($statistics)]);
$db = DriverManager::getConnection([
    'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1', 'port' => getenv('PGPORT') ?: 5432,
    'user' => getenv('PGUSER') ?: 'postgres', 'password' => getenv('PGPASSWORD') ?: 'postgres',
    'dbname' => getenv('PGDATABASE') ?: 'modernorm_test', 'serverVersion' => '17',
], $dbal);
$schema = 'modernorm_bulk_bench_'.bin2hex(random_bytes(6));
$table = $db->quoteIdentifier($schema).'.record';
$create = static function () use ($db, $schema): EntityManager {
    $config = new Configuration();
    $config->setMetadataDriverImpl(new AttributeDriver([]));
    $em = new EntityManager($db, $config);
    $em->getClassMetadata(Record::class)->setPrimaryTable(['name' => 'record', 'schema' => $schema]);
    return $em;
};
$managedCount = static fn (EntityManager $em): int => array_sum(array_map('count', $em->getUnitOfWork()->getIdentityMap()));

$measure = static function (string $operation, string $mode, int $rows) use (
    $db, $table, $statistics, $create, $managedCount, $contextSize, $chunkSize,
): array {
    // Rebuild identical database and persistence-context state outside the timed interval.
    $db->executeStatement('TRUNCATE '.$table);
    $db->executeStatement("INSERT INTO $table SELECT -id, 'context', 0 FROM generate_series(1, ?) AS id", [$contextSize]);
    if ($operation !== 'insert') {
        $db->executeStatement("INSERT INTO $table SELECT id, 'original', 0 FROM generate_series(1, ?) AS id", [$rows]);
    }
    $em = $create();
    $context = $em->getRepository(Record::class)->findBy(['label' => 'context']);
    $targets = [];
    if ($operation !== 'insert') {
        // Include affected objects so BYPASS demonstrably leaves stale state.
        $targets = $em->getRepository(Record::class)->findBy(['label' => 'original'], ['id' => 'ASC'], min($rows, $contextSize));
    }
    $managedBefore = $managedCount($em);
    gc_collect_cycles();
    memory_reset_peak_usage();
    $heapBefore = memory_get_usage();
    $statistics->reset();
    $start = hrtime(true);
    try {
        $db->beginTransaction();
        if ($mode === 'uow') {
            if ($operation === 'insert') {
                for ($id = 1; $id <= $rows; ++$id) {
                    $record = new Record(); $record->id = $id; $record->label = 'inserted'; $record->amount = 1;
                    $em->persist($record);
                }
            } else {
                $records = $em->getRepository(Record::class)->findBy(['label' => 'original'], ['id' => 'ASC']);
                foreach ($records as $record) {
                    if ($operation === 'update') { $record->label = 'updated'; $record->amount = 1; }
                    else { $em->remove($record); }
                }
            }
            $em->flush();
        } else {
            $policy = $mode === 'bypass' ? BulkPolicy::BYPASS : BulkPolicy::CLEAR;
            $affected = 0;
            if ($operation === 'insert') {
                for ($offset = 0; $offset < $rows; $offset += $chunkSize) {
                    $batch = [];
                    for ($id = $offset + 1; $id <= min($rows, $offset + $chunkSize); ++$id) {
                        $batch[] = ['id' => $id, 'label' => 'inserted', 'amount' => 1];
                    }
                    $affected += $em->bulk()->insert(Record::class, $batch, $policy)->affectedRows;
                }
            } elseif ($operation === 'update') {
                $affected = $em->bulk()->update(Record::class, ['label' => 'updated', 'amount' => 1], 'id > 0', $policy)->affectedRows;
            } else {
                $affected = $em->bulk()->delete(Record::class, 'id > 0', $policy)->affectedRows;
            }
        }
        $db->commit();
        $elapsed = (hrtime(true) - $start) / 1_000_000;
        $peak = memory_get_peak_usage() - $heapBefore;
        $retained = memory_get_usage() - $heapBefore;
        $sqlCount = $statistics->snapshot()->sqlCount;
        $managedAfter = $managedCount($em);

        // Validate database contents, SQL counts and policy effects after measurement.
        // flush() nests in the shared outer transaction: SAVEPOINT + RELEASE are SQL too.
        $expectedSql = $mode === 'uow' ? $rows + ($operation === 'insert' ? 0 : 1) + 2
            : ($operation === 'insert' ? (int) ceil($rows / $chunkSize) : 1);
        if ($sqlCount !== $expectedSql || ($mode !== 'uow' && $affected !== $rows)) {
            throw new \RuntimeException("Unexpected SQL/affected-row count: $operation/$mode SQL=$sqlCount expected=$expectedSql.");
        }
        $expectedManaged = $mode === 'clear' ? 0 : ($mode === 'bypass' ? $managedBefore : $contextSize + ($operation === 'delete' ? 0 : $rows));
        if ($managedAfter !== $expectedManaged || ($mode === 'bypass' && $targets !== [] && $targets[0]->label !== 'original')) {
            throw new \RuntimeException('Unexpected UnitOfWork policy outcome.');
        }
        $result = $db->fetchAssociative("SELECT count(*) AS n, COALESCE(sum(id), 0) AS ids, COALESCE(sum(amount), 0) AS amounts, count(*) FILTER (WHERE label = ?) AS labels FROM $table WHERE id > 0", [$operation === 'update' ? 'updated' : 'inserted']);
        $expectedRows = $operation === 'delete' ? 0 : $rows;
        if ((int) $result['n'] !== $expectedRows || (int) $result['ids'] !== intdiv($expectedRows * ($expectedRows + 1), 2)
            || (int) $result['amounts'] !== $expectedRows || (int) $result['labels'] !== $expectedRows
            || (int) $db->fetchOne("SELECT count(*) FROM $table WHERE id < 0 AND label = 'context' AND amount = 0") !== $contextSize) {
            throw new \RuntimeException('Database result differs between strategies.');
        }
        return ['wall_ms' => $elapsed, 'rows_per_second' => $rows / ($elapsed / 1000), 'sql_statements' => $sqlCount,
            'peak_heap_delta_bytes' => $peak, 'retained_heap_delta_bytes' => $retained,
            'managed_before' => $managedBefore, 'managed_after' => $managedAfter];
    } finally {
        while ($db->isTransactionActive()) { $db->rollBack(); }
        $em->close();
    }
};

try {
    $db->executeStatement('CREATE SCHEMA '.$db->quoteIdentifier($schema));
    $db->executeStatement("CREATE TABLE $table (id INTEGER PRIMARY KEY, label VARCHAR(255) NOT NULL, amount INTEGER NOT NULL)");
    $cases = [];
    foreach (['insert', 'update', 'delete'] as $operation) {
        foreach (['uow', 'bypass', 'clear'] as $mode) { $cases[] = [$operation, $mode]; }
    }
    foreach ($cases as [$operation, $mode]) { $measure($operation, $mode, min(1000, min($sizes))); }
    $measurements = [];
    foreach (array_unique($sizes) as $rows) {
        $samples = [];
        for ($round = 0; $round < $rounds; ++$round) {
            $offset = ($round * 4) % count($cases);
            foreach (array_merge(array_slice($cases, $offset), array_slice($cases, 0, $offset)) as [$operation, $mode]) {
                fwrite(STDERR, "rows=$rows round=".($round + 1)."/$rounds $operation/$mode\n");
                $samples[$operation][$mode][] = $measure($operation, $mode, $rows);
            }
        }
        foreach ($samples as $operation => $modes) {
            foreach ($modes as $mode => $runs) {
                $times = array_column($runs, 'wall_ms');
                $measurements[] = ['operation' => $operation, 'mode' => $mode, 'rows' => $rows,
                    'median_ms' => median($times), 'min_ms' => min($times), 'max_ms' => max($times),
                    'median_rows_per_second' => median(array_column($runs, 'rows_per_second')),
                    'median_peak_heap_delta_bytes' => median(array_column($runs, 'peak_heap_delta_bytes')),
                    'samples' => $runs];
            }
        }
    }
    echo json_encode(['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'postgresql' => $db->fetchOne('SHOW server_version'),
        'memory_limit' => ini_get('memory_limit'), 'rounds' => $rounds, 'insert_chunk_size' => $chunkSize,
        'context_rows' => $contextSize, 'l2_enabled' => false, 'statistics_enabled' => true,
        'warmup_rows' => min(1000, min($sizes)), 'measurements' => $measurements,
        'method' => 'One outer transaction including commit per sample. Timed: input/entity construction, target hydration for UoW UPDATE/DELETE, DML and policy handling. Setup, context preloading and verification excluded. SQL counts include the two flush SAVEPOINT statements, but exclude driver begin/commit calls. One warm-up per case; case order rotates. Assigned IDs, no associations/callbacks/RETURNING/L2. PHP heap deltas, not RSS; retained references to preloaded entities stay alive for all modes. CLEAR empties the context on the first INSERT chunk. No Doctrine comparison or timing thresholds.',
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} finally {
    while ($db->isTransactionActive()) { $db->rollBack(); }
    $db->executeStatement('DROP SCHEMA IF EXISTS '.$db->quoteIdentifier($schema).' CASCADE');
    $db->close();
}
