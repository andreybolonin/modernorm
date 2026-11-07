<?php

declare(strict_types=1);

namespace ModernORMHydrationBenchmark;

require dirname(__DIR__) . '/vendor/autoload.php';

use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Internal\Hydration\ArrayHydrator;
use ModernORM\Internal\Hydration\ObjectHydrator;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Query\ResultSetMapping;

#[ORM\Entity]
class Cart
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;
    #[ORM\Column(type: 'json')]
    public array $payload;
    #[ORM\Column(type: 'datetime_immutable')]
    public DateTimeImmutable $createdAt;
    #[ORM\Column(type: 'datetime_immutable')]
    public DateTimeImmutable $updatedAt;
    #[ORM\OneToMany(mappedBy: 'cart', targetEntity: Item::class)]
    public Collection $items;
}

#[ORM\Entity]
class Item
{
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;
    #[ORM\Column(type: 'json')]
    public array $payload;
    #[ORM\ManyToOne(targetEntity: Cart::class, inversedBy: 'items')]
    public Cart $cart;
}

$db = DriverManager::getConnection([
    'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
    'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
    'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
]);
$config = new Configuration();
$config->setMetadataDriverImpl(new AttributeDriver([]));
$iterations = max(3, (int) (getenv('MODERNORM_BENCH_ITERATIONS') ?: 15));
$measurements = [];
foreach (['root' => 0, '1:1' => 1, '1:10' => 10, '1:100' => 100, '1:1000' => 1000, 'empty-left' => -1, 'array-1:100' => 100] as $scenario => $fanout) {
    $joined = $fanout !== 0;
    $parents = $fanout > 0 ? intdiv(10000, $fanout) : 10000;
    $sql = "SELECT p AS p_id, '{\"name\":\"cart\",\"tags\":[1,2,3],\"nested\":{\"enabled\":true}}' AS p_payload, "
        . "'2026-11-07 12:34:56' AS p_created, '2026-11-07 13:34:56' AS p_updated";
    if ($joined) {
        $sql .= $fanout < 0 ? ', NULL AS i_id, NULL AS i_payload, NULL AS i_cart' : ", ((p-1)*$fanout+i) AS i_id, '{\"quantity\":3}' AS i_payload, p AS i_cart";
    }
    $sql .= " FROM generate_series(1, $parents) AS p";
    if ($fanout > 0) {
        $sql .= " CROSS JOIN generate_series(1, $fanout) AS i";
    }
    $times = $peaks = [];
    for ($iteration = -2; $iteration < $iterations; ++$iteration) {
        $em = new EntityManager($db, $config);
        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(Cart::class, 'p');
        foreach (['p_id' => 'id', 'p_payload' => 'payload', 'p_created' => 'createdAt', 'p_updated' => 'updatedAt'] as $column => $field) {
            $rsm->addFieldResult('p', $column, $field);
        }
        if ($joined) {
            $rsm->addJoinedEntityResult(Item::class, 'i', 'p', 'items');
            $rsm->addFieldResult('i', 'i_id', 'id');
            $rsm->addFieldResult('i', 'i_payload', 'payload');
            $rsm->addMetaResult('i', 'i_cart', 'cart_id', false, 'integer');
        }
        $array = str_starts_with($scenario, 'array-');
        $hydrator = $array ? new ArrayHydrator($em) : new ObjectHydrator($em);
        // Warm metadata; measure hydration and driver fetching, excluding SQL execution.
        $em->getClassMetadata(Cart::class);
        $em->getClassMetadata(Item::class);
        $stmt = $db->executeQuery($sql);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $memory = memory_get_usage();
        $start = hrtime(true);
        $result = $hydrator->hydrateAll($stmt, $rsm);
        $elapsed = (hrtime(true) - $start) / 1e6;
        $peak = memory_get_peak_usage() - $memory;
        if (count($result) !== $parents) {
            throw new \RuntimeException('Incorrect root count.');
        }
        if ($joined) {
            $items = $array ? $result[0]['items'] : $result[0]->items;
            if (count($items) !== max(0, $fanout)) {
                throw new \RuntimeException('Incorrect collection count.');
            }
            unset($items);
        }
        if ($iteration >= 0) {
            $times[] = $elapsed;
            $peaks[] = $peak;
        }
        $em->clear();
        unset($result, $hydrator, $em, $stmt);
    }
    sort($times);
    sort($peaks);
    $measurements[$scenario] = ['samples_ms' => $times, 'peak_delta_samples_bytes' => $peaks, 'median_ms' => $times[intdiv($iterations, 2)], 'median_peak_delta_bytes' => $peaks[intdiv($iterations, 2)], 'roots' => $parents, 'rows' => 10000];
}
echo json_encode(['php' => PHP_VERSION, 'dbal' => \Composer\InstalledVersions::getPrettyVersion('doctrine/dbal'), 'postgresql' => $db->fetchOne('SHOW server_version'), 'os' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'iterations' => $iterations, 'measurements' => $measurements], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
