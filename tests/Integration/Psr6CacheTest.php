<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Tests\Support\CacheRecord;
use ModernORM\Tests\Support\CountingCacheDriver;
use ModernORM\Tests\Support\RecordingCachePool;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Stringable;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

require_once dirname(__DIR__) . '/Support/CacheFixtures.php';

final class CacheSqlRecorder extends AbstractLogger
{
    public array $sql = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql'])) {
            $this->sql[] = $context['sql'];
        }
    }
}

final class Psr6CacheTest extends TestCase
{
    private string $directory;
    /** @var list<EntityManager> */
    private array $managers = [];
    private CacheSqlRecorder $sql;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/modernorm-psr6-' . bin2hex(random_bytes(8));
        $this->sql = new CacheSqlRecorder();
        [$em] = $this->manager('v1');
        $connection = $em->getConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS modernorm_test_cache_records');
        $connection->executeStatement('CREATE TABLE modernorm_test_cache_records (id INT PRIMARY KEY, name_v1 VARCHAR(255) NOT NULL)');
        $connection->executeStatement("INSERT INTO modernorm_test_cache_records VALUES (1, 'original')");
    }

    protected function tearDown(): void
    {
        try {
            if ($this->managers !== []) {
                $this->managers[0]->getConnection()->executeStatement('DROP TABLE IF EXISTS modernorm_test_cache_records');
            }
        } finally {
            foreach ($this->managers as $em) {
                $em->getConnection()->close();
            }
            if (is_dir($this->directory)) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($files as $file) {
                    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                }
                rmdir($this->directory);
            }
        }
    }

    /** @return array{EntityManager, CountingCacheDriver, RecordingCachePool, RecordingCachePool} */
    private function manager(string $version, string $column = 'name_v1'): array
    {
        // Independent adapter instances use the same on-disk pool within a version.
        $metadata = new RecordingCachePool(new FilesystemAdapter('metadata_' . $version, directory: $this->directory));
        $query = new RecordingCachePool(new FilesystemAdapter('query_' . $version, directory: $this->directory));
        $driver = new CountingCacheDriver($column);
        $config = new Configuration();
        $config->setMetadataDriverImpl($driver);
        $config->setMetadataCache($metadata);
        $config->setQueryCache($query);
        $dbal = new DBALConfiguration();
        $dbal->setMiddlewares([new Middleware($this->sql)]);
        $em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'serverVersion' => '17',
            'host' => getenv('PGHOST') ?: '127.0.0.1', 'port' => getenv('PGPORT') ?: 5432,
            'user' => getenv('PGUSER') ?: 'postgres', 'password' => getenv('PGPASSWORD') ?: 'postgres',
            'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $dbal), $config);
        $this->managers[] = $em;

        return [$em, $driver, $metadata, $query];
    }

    private function query(EntityManager $em): \ModernORM\Query
    {
        return $em->createQueryBuilder()->fromEntity(CacheRecord::class, 'r')->where('r.id = :id')->getQuery();
    }

    public function testFilesystemHitAcrossManagersStillReadsCurrentDatabaseRows(): void
    {
        [$first, $firstDriver, $metadata, $queries] = $this->manager('v1');
        $record = $this->query($first)->setParameter('id', 1)->getSingleResult();
        self::assertSame('original', $record->name);
        self::assertSame(1, $firstDriver->loads);
        self::assertContains(false, array_column($metadata->reads, 1));
        self::assertSame([false], array_column($queries->reads, 1));
        $first->getConnection()->executeStatement("UPDATE modernorm_test_cache_records SET name_v1 = 'external-update' WHERE id = 1");
        [$second, $secondDriver, $metadataHit, $queryHit] = $this->manager('v1');
        $this->sql->sql = [];
        $current = $this->query($second)->setParameter('id', 1)->getSingleResult();
        self::assertSame('external-update', $current->name);
        self::assertNotSame($record, $current);
        self::assertSame(0, $secondDriver->loads);
        self::assertSame([true], array_column($metadataHit->reads, 1));
        self::assertSame([true], array_column($queryHit->reads, 1));
        self::assertSame([], $metadataHit->writes);
        self::assertSame([], $queryHit->writes);
        self::assertCount(1, $this->sql->sql);
        self::assertStringStartsWith('SELECT', $this->sql->sql[0]);
        self::assertNull($second->getCache());
    }

    public function testVersionedMetadataAndQueryNamespacesHandleMappingDeployment(): void
    {
        [$first] = $this->manager('v1');
        $oldSql = $this->query($first)->setParameter('id', 1)->getSQL();
        self::assertStringContainsString('name_v1', $oldSql);
        $first->getConnection()->executeStatement('ALTER TABLE modernorm_test_cache_records RENAME COLUMN name_v1 TO name_v2');
        [$second, $driver, $metadata, $queries] = $this->manager('v2', 'name_v2');
        $query = $this->query($second)->setParameter('id', 1);
        $newSql = $query->getSQL();
        self::assertStringContainsString('name_v2', $newSql);
        self::assertStringNotContainsString('name_v1', $newSql);
        self::assertSame('original', $query->getSingleResult()->name);
        self::assertSame(1, $driver->loads);
        self::assertContains(false, array_column($metadata->reads, 1));
        self::assertSame([false], array_column($queries->reads, 1));
        [$third, $hitDriver, $metadataHit, $queryHit] = $this->manager('v2', 'name_v2');
        self::assertSame('original', $this->query($third)->setParameter('id', 1)->getSingleResult()->name);
        self::assertSame(0, $hitDriver->loads);
        self::assertSame([true], array_column($metadataHit->reads, 1));
        self::assertSame([true], array_column($queryHit->reads, 1));
        // The old version is isolated, not silently rewritten by deployment v2.
        [$old] = $this->manager('v1', 'name_v2');
        self::assertSame($oldSql, $this->query($old)->setParameter('id', 1)->getSQL());
    }
}
