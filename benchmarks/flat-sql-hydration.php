<?php

/*
 * This file is part of the ModernORM package.
 *
 * (c) Andrey Bolonin <andreybolonin1989@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ModernORMFlatSqlBenchmark;

require dirname(__DIR__) . '/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Internal\Hydration\ObjectHydrator;
use ModernORM\Internal\Hydration\SimpleObjectHydrator;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Query\ResultSetMapping;
use RuntimeException;

#[ORM\Entity]
class Small
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column] public string $name;
}

#[ORM\Entity]
class Wide
{
    public static int $hooks = 0;
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column(nullable: true)] private ?string $name;
    #[ORM\Column] protected int $quantity;
    #[ORM\Column] public bool $active;
    #[ORM\Column] public float $price;
    #[ORM\Column] public string $a;
    #[ORM\Column] public string $b;
    #[ORM\Column] public string $c;
    #[ORM\Column] public string $d;
    #[ORM\Column] public string $e;
    #[ORM\Column] public string $f;
    #[ORM\Column] public string $hooked {
        set {
            ++self::$hooks;
            $this->hooked = $value;
        }
    }
    public function __construct()
    {
        throw new RuntimeException('Constructor must not run.');
    }
}

#[ORM\Entity]
class JsonControl
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column(type: 'json')] public array $payload;
}

$db = DriverManager::getConnection([
    'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
    'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
    'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
]);
$config = new Configuration();
$config->setMetadataDriverImpl(new AttributeDriver([]));
$rows = max(1, (int) (getenv('MODERNORM_BENCH_ROWS') ?: 20000));
$rounds = max(3, (int) (getenv('MODERNORM_BENCH_ROUNDS') ?: 9));
$cases = [
    'small' => [Small::class, ['id' => 'p', 'name' => "'name'"], ['name' => 'name']],
    'wide' => [Wide::class, ['id' => 'p', 'name' => "'name'", 'quantity' => '7', 'active' => 'TRUE', 'price' => '1.25',
        'a' => "'a'", 'b' => "'b'", 'c' => "'c'", 'd' => "'d'", 'e' => "'e'", 'f' => "'f'", 'hooked' => "'raw'"],
        ['name' => 'name', 'quantity' => 7, 'active' => true, 'price' => 1.25, 'a' => 'a', 'b' => 'b', 'c' => 'c', 'd' => 'd', 'e' => 'e', 'f' => 'f', 'hooked' => 'raw']],
    'json-control' => [JsonControl::class, ['id' => 'p', 'payload' => "'{\"tags\":[1,2,3]}'"], ['payload' => ['tags' => [1, 2, 3]]]],
];
$measurements = [];
foreach (['object' => ObjectHydrator::class, 'simple' => SimpleObjectHydrator::class] as $mode => $hydratorClass) {
    foreach ($cases as $name => [$class, $columns, $expected]) {
        $rsm = new ResultSetMapping();
        $rsm->addEntityResult($class, 'r');
        $select = [];
        foreach ($columns as $field => $expression) {
            $column = 'v_' . $field;
            $rsm->addFieldResult('r', $column, $field);
            $select[] = $expression . ' AS ' . $column;
        }
        $sql = 'SELECT ' . implode(', ', $select) . " FROM generate_series(1, $rows) AS p";
        $times = $peaks = [];
        for ($round = -2; $round < $rounds; ++$round) {
            $em = new EntityManager($db, $config);
            $metadata = $em->getClassMetadata($class);
            $hydrator = new $hydratorClass($em);
            $statement = $db->executeQuery($sql);
            gc_collect_cycles();
            memory_reset_peak_usage();
            $before = memory_get_usage();
            $start = hrtime(true);
            $result = $hydrator->hydrateAll($statement, $rsm);
            $time = (hrtime(true) - $start) / 1e6;
            $peak = memory_get_peak_usage() - $before;
            if (count($result) !== $rows || $em->getUnitOfWork()->size() !== $rows) {
                throw new RuntimeException('Wrong managed root count.');
            }
            foreach ($result as $index => $entity) {
                if ($entity->id !== $index + 1) {
                    throw new RuntimeException('Wrong identifier/order.');
                }
                foreach ($expected as $field => $value) {
                    if ($metadata->propertyAccessors[$field]->getValue($entity) !== $value) {
                        throw new RuntimeException('Wrong converted field: ' . $field);
                    }
                }
            }
            if (Wide::$hooks !== 0) {
                throw new RuntimeException('A property hook ran.');
            }
            if ($round >= 0) {
                $times[] = $time;
                $peaks[] = $peak;
            }
            $em->clear();
            unset($result, $entity, $hydrator, $statement, $metadata, $em);
        }
        $sorted = $times;
        sort($sorted);
        $sortedPeaks = $peaks;
        sort($sortedPeaks);
        $measurements[$mode . '-' . $name] = ['median_ms' => $sorted[intdiv($rounds, 2)],
            'median_peak_delta_bytes' => $sortedPeaks[intdiv($rounds, 2)], 'samples_ms' => $times, 'peak_delta_samples_bytes' => $peaks];
    }
}
echo json_encode(['php' => PHP_VERSION, 'deepclone' => phpversion('deepclone'), 'os' => PHP_OS_FAMILY,
    'arch' => php_uname('m'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
    'postgresql' => $db->fetchOne('SHOW server_version'), 'rows' => $rows, 'rounds' => $rounds,
    'scope' => 'Managed SQL hydration including driver fetch/conversion/identity map/snapshots; excludes SQL execution and validation',
    'measurements' => $measurements], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
