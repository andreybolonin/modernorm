<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Types;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Query\EntityResultPlan;
use ModernORM\Query\ResultSetMapping;
use ModernORM\Tests\Support\CacheRecord;
use ModernORM\Tests\Support\CountingCacheDriver;
use ModernORM\Tests\Support\RecordingCachePool;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

require_once dirname(__DIR__) . '/Support/CacheFixtures.php';


final class Psr6CacheTest extends TestCase
{
    private function manager(CountingCacheDriver $driver, CacheItemPoolInterface $metadata, CacheItemPoolInterface $query): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl($driver);
        $config->setMetadataCache($metadata);
        $config->setQueryCache($query);

        return new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'serverVersion' => '17',
        ]), $config);
    }

    private function query(EntityManager $em): \ModernORM\Query
    {
        $builder = $em->createQueryBuilder()->fromEntity(CacheRecord::class, 'r');
        return $builder->where($builder->column('r', 'name') . ' = :name')->getQuery();
    }

    public function testMetadataMissThenHitAcrossManagersRestoresIndependentMetadata(): void
    {
        $pool = new RecordingCachePool(new ArrayAdapter());
        $query = new ArrayAdapter();
        $firstDriver = new CountingCacheDriver();
        $first = $this->manager($firstDriver, $pool, $query);
        $metadata = $first->getClassMetadata(CacheRecord::class);
        self::assertSame(1, $firstDriver->loads);
        self::assertFalse($pool->reads[0][1]);
        self::assertCount(1, $pool->writes);
        $reads = count($pool->reads);
        self::assertSame($metadata, $first->getClassMetadata(CacheRecord::class));
        self::assertCount($reads, $pool->reads);
        $secondDriver = new CountingCacheDriver();
        $second = $this->manager($secondDriver, $pool, $query);
        $restored = $second->getClassMetadata(CacheRecord::class);
        self::assertSame(0, $secondDriver->loads);
        self::assertTrue($pool->reads[array_key_last($pool->reads)][1]);
        self::assertNotSame($metadata, $restored);
        self::assertSame('name_v1', $restored->getColumnName('name'));
        $record = $restored->newInstance();
        $restored->setFieldValue($record, 'name', 'restored reflection');
        self::assertSame('restored reflection', $record->name);
        self::assertFalse($second->getConnection()->isConnected());
        self::assertFalse($second->getConfiguration()->isSecondLevelCacheEnabled());
    }

    public function testEntityPlanMissThenHitAcrossManagers(): void
    {
        $metadata = new ArrayAdapter();
        $pool = new RecordingCachePool(new ArrayAdapter());
        $first = $this->manager(new CountingCacheDriver(), $metadata, $pool);
        $sql = $this->query($first)->setParameter('name', 'first')->getSQL();
        self::assertSame([false], array_column($pool->reads, 1));
        self::assertCount(1, $pool->writes);
        self::assertInstanceOf(EntityResultPlan::class, $pool->getItem($pool->writes[0])->get());
        $second = $this->manager(new CountingCacheDriver(), $metadata, $pool);
        $pool->reads = [];
        self::assertSame($sql, $this->query($second)->setParameter('name', 'second')->getSQL());
        self::assertSame([true], array_column($pool->reads, 1));
        self::assertCount(1, $pool->writes);
        self::assertFalse($second->getConnection()->isConnected());
        // Projection plans depend on metadata and aliases, not runtime values or parameter types.
        $this->query($second)->setParameter('name', 'second', Types::ASCII_STRING)->getSQL();
        self::assertSame([true, true], array_column($pool->reads, 1));
        self::assertCount(1, $pool->writes);
    }

    public function testMetadataAndQueryEntriesCanShareOnePool(): void
    {
        $pool = new RecordingCachePool(new ArrayAdapter());
        $first = $this->manager(new CountingCacheDriver(), $pool, $pool);
        $first->getClassMetadata(CacheRecord::class);
        $sql = $this->query($first)->getSQL();
        self::assertCount(2, $pool->writes);
        self::assertCount(2, array_unique($pool->writes));
        $pool->reads = [];
        $driver = new CountingCacheDriver();
        $second = $this->manager($driver, $pool, $pool);
        self::assertSame('name_v1', $second->getClassMetadata(CacheRecord::class)->getColumnName('name'));
        self::assertSame($sql, $this->query($second)->getSQL());
        self::assertSame(0, $driver->loads);
        self::assertSame([true, true], array_column($pool->reads, 1));
        self::assertCount(2, $pool->writes);
    }

    public function testExplicitSqlMappingDoesNotConsultEntityPlanCache(): void
    {
        $pool = new RecordingCachePool(new ArrayAdapter());
        $em = $this->manager(new CountingCacheDriver(), new ArrayAdapter(), $pool);
        $mapping = new ResultSetMapping();
        $mapping->addScalarResult('id', 'id', 'integer');
        $query = $em->createQuery('SELECT id FROM modernorm_test_cache_records', $mapping);
        self::assertSame('SELECT id FROM modernorm_test_cache_records', $query->getSQL());
        self::assertSame([], $pool->reads);
        self::assertSame([], $pool->writes);
    }

    public function testExpiredProjectionEntryIsRebuiltWithoutSleeping(): void
    {
        $pool = new RecordingCachePool(new ArrayAdapter());
        $em = $this->manager(new CountingCacheDriver(), new ArrayAdapter(), $pool);
        $sql = $this->query($em)->getSQL();
        $item = $pool->getItem($pool->writes[0]);
        $pool->save($item->expiresAfter(0));
        $pool->reads = $pool->writes = [];
        self::assertSame($sql, $this->query($em)->getSQL());
        self::assertSame([false], array_column($pool->reads, 1));
        self::assertCount(1, $pool->writes);
    }

    public function testMappingChangeRequiresInvalidatingBothPoolsAndNewManager(): void
    {
        $metadata = new ArrayAdapter();
        $queries = new ArrayAdapter();
        $first = $this->manager(new CountingCacheDriver(), $metadata, $queries);
        $oldSql = $this->query($first)->getSQL();
        self::assertStringContainsString('name_v1', $oldSql);
        $changed = new CountingCacheDriver('name_v2');
        $stale = $this->manager($changed, $metadata, $queries);
        self::assertSame('name_v1', $stale->getClassMetadata(CacheRecord::class)->getColumnName('name'));
        self::assertSame(0, $changed->loads);
        $metadata->clear();
        $next = $this->manager(new CountingCacheDriver('name_v2'), $metadata, $queries);
        self::assertSame('name_v2', $next->getClassMetadata(CacheRecord::class)->getColumnName('name'));
        self::assertSame($oldSql, $this->query($next)->getSQL());
        $queries->clear();
        // clear() on the EntityManager only clears entities, not loaded metadata.
        $stale->clear();
        self::assertSame('name_v1', $stale->getClassMetadata(CacheRecord::class)->getColumnName('name'));
        $fresh = $this->manager(new CountingCacheDriver('name_v2'), $metadata, $queries);
        $newSql = $this->query($fresh)->getSQL();
        self::assertStringContainsString('name_v2', $newSql);
        self::assertStringNotContainsString('name_v1', $newSql);
    }


}
