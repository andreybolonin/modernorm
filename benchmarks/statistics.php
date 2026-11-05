<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;
use ModernORM\Statistics\OperationEvent;
use ModernORM\Statistics\OperationObserver;

$iterations = max(10, min(10000, (int) (getenv('MODERNORM_BENCH_ITERATIONS') ?: 200)));
$rounds = max(3, min(25, (int) (getenv('MODERNORM_BENCH_ROUNDS') ?: 7)));
$modes = ['disabled', 'counters', 'observer'];
$connections = [];
$collectors = [];
$samples = [];
$memory = [];
foreach ($modes as $mode) {
    $observer = $mode === 'observer' ? new class () implements OperationObserver {
        public function completed(OperationEvent $event): void
        {
        }
    } : null;
    $statistics = new Statistics($mode !== 'disabled', $observer);
    $config = new Configuration();
    $config->setMiddlewares([new Middleware($statistics)]);
    $connections[$mode] = DriverManager::getConnection([
        'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1', 'port' => getenv('PGPORT') ?: 5432,
        'user' => getenv('PGUSER') ?: 'postgres', 'password' => getenv('PGPASSWORD') ?: 'postgres',
        'dbname' => getenv('PGDATABASE') ?: 'modernorm_test', 'serverVersion' => '17',
    ], $config);
    $collectors[$mode] = $statistics;
    for ($i = 0; $i < 20; ++$i) {
        $connections[$mode]->fetchOne('SELECT 1');
    }
}
try {
    for ($round = 0; $round < $rounds; ++$round) {
        // Rotate ordering so the disabled case is not always measured first.
        foreach (array_merge(array_slice($modes, $round % 3), array_slice($modes, 0, $round % 3)) as $mode) {
            $collectors[$mode]->reset();
            gc_collect_cycles();
            memory_reset_peak_usage();
            $before = memory_get_usage();
            $start = hrtime(true);
            for ($i = 0; $i < $iterations; ++$i) {
                $connections[$mode]->fetchOne('SELECT 1');
            }
            $samples[$mode][] = (hrtime(true) - $start) / 1_000_000;
            $memory[$mode][] = ['retained_bytes' => memory_get_usage() - $before, 'peak_delta_bytes' => memory_get_peak_usage() - $before];
            $expected = $mode === 'disabled' ? 0 : $iterations;
            if ($collectors[$mode]->snapshot()->sqlCount !== $expected) {
                throw new RuntimeException('Statistics count mismatch.');
            }
        }
    }
    $summary = [];
    foreach ($samples as $mode => $times) {
        sort($times);
        $count = count($times);
        $middle = intdiv($count, 2);
        $median = $count % 2 ? $times[$middle] : ($times[$middle - 1] + $times[$middle]) / 2;
        $summary[$mode] = ['median_ms' => $median, 'min_ms' => min($times), 'max_ms' => max($times), 'memory' => $memory[$mode]];
    }
    echo json_encode(['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'postgresql' => $connections['disabled']->fetchOne('SHOW server_version'), 'query' => 'SELECT 1 (including result fetch)', 'iterations_per_round' => $iterations, 'rounds' => $rounds, 'measurements' => $summary, 'note' => 'Local microbenchmark; no latency thresholds or Doctrine comparison.'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} finally {
    foreach ($connections as $connection) {
        $connection->close();
    }
}
