<?php

declare(strict_types=1);

namespace ModernORM\Tests\Support;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use ModernORM\Cache\CacheWritePolicy;
use ModernORM\Cache\EntityCacheEntry;
use ModernORM\Cache\EntityCacheKey;
use ModernORM\Cache\Region\ReadOnlyRegion;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;

#[ORM\Entity, ORM\Table(name: 'record'), ORM\Cacheable(ttl: 60, writePolicy: CacheWritePolicy::INVALIDATE), ORM\HasLifecycleCallbacks]
final class RedisL2Record
{
    public static ?\Closure $afterUpdate = null;
    #[ORM\Id, ORM\Column(type: 'integer')] public int $id;
    #[ORM\Column] public string $name;
    #[ORM\Version, ORM\Column(type: 'integer')] public int $version = 1;
    #[ORM\PostUpdate] public function afterUpdate(): void { (self::$afterUpdate ?? static fn () => null)(); }
}

final class RedisL2Pool extends RedisAdapter
{
    public bool $failNextSave = false;
    public ?\Closure $beforeSave = null;
    public array $writes = [];
    public bool $recordWrites = false;
    public function save(CacheItemInterface $item): bool
    {
        if ($this->beforeSave !== null) {
            $callback = $this->beforeSave; $this->beforeSave = null; $callback();
        }
        if ($this->failNextSave) { $this->failNextSave = false; return false; }
        if ($this->recordWrites) { $this->writes[] = $item->getKey(); }
        return parent::save($item);
    }
}

final class RedisL2CommitPause
{
    public ?\Closure $afterCommit = null;
}

final class RedisL2CommitMiddleware implements \Doctrine\DBAL\Driver\Middleware
{
    public function __construct(private readonly RedisL2CommitPause $pause) {}
    public function wrap(\Doctrine\DBAL\Driver $driver): \Doctrine\DBAL\Driver
    {
        return new class ($driver, $this->pause) extends \Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware {
            public function __construct(\Doctrine\DBAL\Driver $driver, private readonly RedisL2CommitPause $pause) { parent::__construct($driver); }
            public function connect(array $params): \Doctrine\DBAL\Driver\Connection
            {
                return new class (parent::connect($params), $this->pause) extends \Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware {
                    public function __construct(\Doctrine\DBAL\Driver\Connection $connection, private readonly RedisL2CommitPause $pause) { parent::__construct($connection); }
                    public function commit(): void
                    {
                        parent::commit();
                        ($this->pause->afterCommit ?? static fn () => null)();
                    }
                };
            }
        };
    }
}

function redisL2Connection(?Statistics $statistics = null, ?RedisL2CommitPause $pause = null): \Doctrine\DBAL\Connection
{
    $config = new DbalConfiguration();
    $middlewares = [];
    if ($statistics !== null) { $middlewares[] = new Middleware($statistics); }
    if ($pause !== null) { $middlewares[] = new RedisL2CommitMiddleware($pause); }
    $config->setMiddlewares($middlewares);
    return DriverManager::getConnection([
        'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1', 'port' => getenv('PGPORT') ?: 5432,
        'user' => getenv('PGUSER') ?: 'postgres', 'password' => getenv('PGPASSWORD') ?: 'postgres',
        'dbname' => getenv('PGDATABASE') ?: 'modernorm_test', 'serverVersion' => '17',
    ], $config);
}

function workerReply(array $response): void
{
    echo json_encode($response, JSON_THROW_ON_ERROR)."\n";
    fflush(STDOUT);
}

function workerBarrier(): void
{
    workerReply(['event' => 'paused']);
    $read = [STDIN]; $write = $except = [];
    if (stream_select($read, $write, $except, 15) !== 1) { throw new \RuntimeException('Worker continuation timed out.'); }
    $line = fgets(STDIN);
    if ($line === false || json_decode($line, true, flags: JSON_THROW_ON_ERROR) !== ['continue' => true]) {
        throw new \RuntimeException('Expected worker continuation.');
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    [$schema, $namespace, $ttl] = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
    $statistics = new Statistics(true);
    $commitPause = new RedisL2CommitPause();
    $db = redisL2Connection($statistics, $commitPause);
    $pool = new RedisL2Pool(RedisAdapter::createConnection(getenv('MODERNORM_TEST_REDIS_DSN'), [
        'timeout' => 0.2, 'read_timeout' => 0.2, 'retry_interval' => 0,
    ]), $namespace, 0);
    $config = new Configuration();
    $config->setMetadataDriverImpl(new AttributeDriver([]));
    $config->setSecondLevelCache($pool, namespace: $namespace);
    $em = new EntityManager($db, $config);
    $em->getClassMetadata(RedisL2Record::class)->setPrimaryTable(['name' => 'record', 'schema' => $schema]);
    $em->getClassMetadata(RedisL2Record::class)->enableCache($ttl, CacheWritePolicy::INVALIDATE);
    $region = new ReadOnlyRegion('protocol_'.$namespace, $pool);
    $key = new EntityCacheKey(RedisL2Record::class, ['id' => 1]);
    workerReply(['ready' => true]);
    try {
        while (($line = fgets(STDIN)) !== false) {
            $request = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $statistics->reset();
            try {
                $result = [];
                switch ($request['op']) {
                    case 'find':
                        if ($request['clear'] ?? true) { $em->clear(); }
                        $record = $em->find(RedisL2Record::class, 1);
                        $result = ['name' => $record?->name, 'version' => $record?->version];
                        break;
                    case 'update':
                        $record = $em->find(RedisL2Record::class, 1);
                        $record->name = $request['name'];
                        RedisL2Record::$afterUpdate = static function () use ($request, $pool): void {
                            if ($request['pause'] ?? false) { workerBarrier(); }
                            if ($request['fail_after_commit'] ?? false) { $pool->failNextSave = true; }
                        };
                        if ($request['fail_before_write'] ?? false) { $pool->failNextSave = true; }
                        if ($request['pause_after_commit'] ?? false) { $commitPause->afterCommit = workerBarrier(...); }
                        $em->flush();
                        $result = ['version' => $record->version];
                        break;
                    case 'delete':
                        $em->remove($em->find(RedisL2Record::class, 1)); $em->flush(); break;
                    case 'insert':
                        $record = new RedisL2Record(); $record->id = 1; $record->name = $request['name'];
                        $em->persist($record); $em->flush(); break;
                    case 'rollback':
                        $db->beginTransaction();
                        try {
                            $db->executeStatement('UPDATE '.$db->quoteIdentifier($schema).'.record SET name = ?', ['private']);
                            $em->clear();
                            $result = ['name' => $em->find(RedisL2Record::class, 1)?->name];
                        } finally { $db->rollBack(); $em->clear(); }
                        break;
                    case 'bulk_update':
                        $result = ['affected' => $em->bulk()->update(RedisL2Record::class, ['name' => $request['name']], 'id = 1', \ModernORM\Bulk\BulkPolicy::CLEAR)->affectedRows];
                        break;
                    case 'bulk_delete':
                        $result = ['affected' => $em->bulk()->delete(RedisL2Record::class, 'id = 1', \ModernORM\Bulk\BulkPolicy::CLEAR)->affectedRows];
                        break;
                    case 'evict': $em->getCache()->evict(RedisL2Record::class, 1); break;
                    case 'ticket':
                        $pool->writes = [];
                        $pool->recordWrites = true;
                        if ($request['pause'] ?? false) { $pool->beforeSave = workerBarrier(...); }
                        $result = ['ticket' => $region->ticket($key), 'metadata_keys' => $pool->writes]; break;
                    case 'publish':
                        $region->putAt($request['ticket'], new EntityCacheEntry(RedisL2Record::class, ['name' => $request['name']])); break;
                    case 'read_region': $result = ['name' => $region->get($key)?->data['name']]; break;
                    case 'evict_region': $region->evict($key); break;
                    case 'lose_metadata': $pool->deleteItems($request['keys']); break;
                    case 'metrics':
                        gc_collect_cycles();
                        $result = ['heap_bytes' => memory_get_usage(), 'allocated_bytes' => memory_get_usage(true),
                            'peak_heap_bytes' => memory_get_peak_usage(),
                            'managed_entities' => array_sum(array_map('count', $em->getUnitOfWork()->getIdentityMap()))];
                        break;
                    default: throw new \RuntimeException('Unknown worker operation.');
                }
                workerReply($result + ['sql' => $statistics->snapshot()->sqlCount]);
            } catch (\Throwable $error) {
                workerReply(['error' => $error::class, 'committed' => $error->databaseCommitted ?? null, 'open' => $em->isOpen()]);
            } finally { RedisL2Record::$afterUpdate = null; $commitPause->afterCommit = null; $pool->recordWrites = false; }
        }
    } finally {
        while ($db->isTransactionActive()) { $db->rollBack(); }
        $em->close(); $db->close();
    }
}
