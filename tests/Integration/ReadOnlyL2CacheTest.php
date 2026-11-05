<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use ModernORM\Cache\Logging\StatisticsCacheLogger;
use ModernORM\Cache\ReadOnlyEntityCacheEntry;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Exception\CacheSynchronizationException;
use ModernORM\Exception\CannotUpdateReadOnlyEntity;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

enum L2State: string
{
    case Active = 'active';
}
#[ORM\Embeddable]
final class L2Details
{
    #[ORM\Column] public string $code = 'detail';
}
#[ORM\Entity, ORM\Table(name: 'modernorm_test_l2_countries'), ORM\Cacheable(ttl: 60), ORM\HasLifecycleCallbacks]
final class L2Country
{
    public static bool $mutateAfterLoad = false;
    #[ORM\Id, ORM\Column, ORM\GeneratedValue] public ?int $id = null;
    #[ORM\Column(unique: true)] public string $name = 'original';
    #[ORM\Column] public L2State $state = L2State::Active;
    #[ORM\Column(type: 'datetime')] public \DateTime $changedAt;
    #[ORM\Column(type: 'json')] public array $payload = ['nested' => ['value' => 1]];
    #[ORM\Column(nullable: true)] public ?string $optional = null;
    #[ORM\Version, ORM\Column] public int $version = 1;
    #[ORM\Embedded(class: L2Details::class)] public L2Details $details;
    public function __construct()
    {
        $this->changedAt = new \DateTime('2026-01-01 12:00:00');
        $this->details = new L2Details();
    }
    #[ORM\PostLoad] public function afterLoad(): void
    {
        if (self::$mutateAfterLoad) {
            $this->name = 'callback';
            $this->changedAt->modify('+1 day');
        }
    }
}
#[ORM\Entity, ORM\Immutable, ORM\Table(name: 'modernorm_test_l2_units'), ORM\Cacheable(ttl: 0)]
final class L2Unit
{
    public function __construct(#[ORM\Id, ORM\Column(length: 32)] public string $id, #[ORM\Column] public string $name)
    {
    }
}
#[ORM\Entity, ORM\Table(name: 'modernorm_test_l2_invoices')]
final class L2Invoice
{
    public function __construct(
        #[ORM\Id, ORM\Column] public int $id,
        #[ORM\ManyToOne(targetEntity: L2Country::class)] public L2Country $country
    ) {
    }
}
#[ORM\Entity, ORM\Cache(usage: 'NONSTRICT_READ_WRITE')]
final class L2UnsupportedStrategy
{
    #[ORM\Id, ORM\Column] public int $id;
}
#[ORM\Entity, ORM\Cacheable]
final class L2UnsupportedGraph
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToOne(targetEntity: L2Country::class)] public L2Country $country;
}
final class ControlledL2Pool extends ArrayAdapter
{
    public bool $failWrites = false;
    public function save(CacheItemInterface $item): bool
    {
        return $this->failWrites ? false : parent::save($item);
    }
}
final class L2CommitLogger extends AbstractLogger
{
    public ?\Closure $onCommit = null;
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($message === 'Committing transaction' && $this->onCommit !== null) {
            ($this->onCommit)();
        }
    }
}
final class ReadOnlyL2CacheTest extends TestCase
{
    private EntityManager $em;
    private ControlledL2Pool $pool;
    private StatisticsCacheLogger $cacheLogger;
    private L2CommitLogger $commitLogger;
    private array $managers = [];
    private array $directories = [];

    private function manager(?CacheItemPoolInterface $pool = null, ?L2CommitLogger $logger = null, string $namespace = 'modernorm'): EntityManager
    {
        $statistics = new Statistics(enabled: true);
        $dbal = new DBALConfiguration();
        $middlewares = [new Middleware($statistics)];
        if ($logger !== null) {
            $middlewares[] = new LoggingMiddleware($logger);
        }
        $dbal->setMiddlewares($middlewares);
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $config->setSecondLevelCache($pool ?? $this->pool, namespace: $namespace, logger: $this->cacheLogger);
        $em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $dbal), $config);
        $this->managers[] = $em;
        return $em;
    }
    protected function setUp(): void
    {
        L2Country::$mutateAfterLoad = false;
        $this->pool = new ControlledL2Pool(storeSerialized: true);
        $this->cacheLogger = new StatisticsCacheLogger();
        $this->commitLogger = new L2CommitLogger();
        $this->em = $this->manager(logger: $this->commitLogger);
        $metadata = array_map($this->em->getClassMetadata(...), [L2Country::class, L2Unit::class, L2Invoice::class]);
        $schema = new SchemaTool($this->em);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }
    protected function tearDown(): void
    {
        $this->pool->failWrites = false;
        $this->commitLogger->onCommit = null;
        L2Country::$mutateAfterLoad = false;
        foreach ($this->managers as $em) {
            if (! $em->getConnection()->isAutoCommit()) {
                $em->getConnection()->setAutoCommit(true);
            } while ($em->getConnection()->isTransactionActive()) {
                $em->getConnection()->rollBack();
            }
        }
        $this->em->getConnection()->executeStatement('DROP TABLE IF EXISTS modernorm_test_l2_invoices, modernorm_test_l2_countries, modernorm_test_l2_units CASCADE');
        foreach ($this->managers as $em) {
            $em->getConnection()->close();
        }
        foreach ($this->directories as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($dir);
        }
    }
    private function seed(): int
    {
        $country = new L2Country();
        $this->em->persist($country);
        $this->em->flush();
        $id = $country->id;
        $this->em->clear();
        return $id;
    }
    private function countSql(EntityManager $em): int
    {
        return $em->getStatistics()->snapshot()->sqlCount;
    }
    private function warm(int $id): L2Country
    {
        return $this->em->find(L2Country::class, $id);
    }

    public function testColdMissWarmHitAcrossManagersAndIdentityMap(): void
    {
        $id = $this->seed();
        self::assertFalse($this->em->getCache()->contains(L2Country::class, $id));
        $this->em->getStatistics()->reset();
        $first = $this->warm($id);
        self::assertSame(1, $this->countSql($this->em));
        $other = $this->manager();
        $second = $other->find(L2Country::class, $id);
        self::assertNotSame($first, $second);
        self::assertSame($second, $other->find(L2Country::class, $id));
        self::assertSame(0, $this->countSql($other));
        self::assertSame('original', $second->name);
        self::assertSame(L2State::Active, $second->state);
        self::assertSame(['nested' => ['value' => 1]], $second->payload);
        self::assertNull($second->optional);
        self::assertSame('detail', $second->details->code);
        self::assertSame(1, $second->version);
        self::assertSame(1, $this->cacheLogger->getHitCount());
        self::assertSame(1, $this->cacheLogger->getMissCount());
        self::assertSame(1, $this->cacheLogger->getPutCount());
    }
    public function testRawEntriesDoNotCapturePostLoadOrMutableObjectChanges(): void
    {
        $id = $this->seed();
        L2Country::$mutateAfterLoad = true;
        $first = $this->warm($id);
        self::assertSame('callback', $first->name);
        L2Country::$mutateAfterLoad = false;
        $second = $this->manager()->find(L2Country::class, $id);
        self::assertSame('original', $second->name);
        self::assertSame('2026-01-01', $second->changedAt->format('Y-m-d'));
        $second->changedAt->modify('+1 year');
        $third = $this->manager()->find(L2Country::class, $id);
        self::assertSame('2026-01-01', $third->changedAt->format('Y-m-d'));
        foreach ($this->pool->getValues() as $key => $value) {
            $entry = $this->pool->getItem($key)->get();
            if ($entry instanceof ReadOnlyEntityCacheEntry) {
                foreach ($entry->data as $field) {
                    self::assertTrue($field === null || is_scalar($field));
                }
            }
        }
    }
    public function testLazyReferenceAndInboundAssociationCanHitL2(): void
    {
        $id = $this->seed();
        $this->warm($id);
        $other = $this->manager();
        $reference = $other->getReference(L2Country::class, $id);
        self::assertTrue($other->isUninitializedObject($reference));
        self::assertSame('original', $reference->name);
        self::assertSame(0, $this->countSql($other));
        $this->em->getConnection()->insert('modernorm_test_l2_invoices', ['id' => 1, 'country_id' => $id]);
        $third = $this->manager();
        $invoice = $third->find(L2Invoice::class, 1);
        self::assertSame('original', $invoice->country->name);
        self::assertSame(1, $this->countSql($third));
    }
    public function testMutableReadOnlyCachedEntityCannotUpdate(): void
    {
        $id = $this->seed();
        $country = $this->warm($id);
        $country->name = 'forbidden';
        try {
            $this->em->flush();
            self::fail('Expected READ_ONLY update rejection');
        } catch (CannotUpdateReadOnlyEntity) {
            self::assertFalse($this->em->isOpen());
        }
        self::assertSame('original', $this->manager()->find(L2Country::class, $id)->name);
    }
    public function testImmutableMarkerIsIndependentOfCacheUsage(): void
    {
        $unit = new L2Unit('kg', 'kilogram');
        $this->em->persist($unit);
        $this->em->flush();
        $this->em->clear();
        $unit = $this->em->find(L2Unit::class, 'kg');
        $unit->name = 'ignored';
        $this->em->flush();
        self::assertSame('kilogram', $this->manager()->find(L2Unit::class, 'kg')->name);
    }
    public function testDeleteCommitEvictsWithoutPublishingManagedState(): void
    {
        $id = $this->seed();
        $country = $this->warm($id);
        $this->em->remove($country);
        $this->em->flush();
        self::assertFalse($this->em->getCache()->contains(L2Country::class, $id));
        self::assertNull($this->manager()->find(L2Country::class, $id));
    }
    public function testRollbackNeverPublishesInsertedRows(): void
    {
        $this->seed();
        $first = new L2Country();
        $first->name = 'duplicate';
        $second = new L2Country();
        $second->name = 'duplicate';
        $this->em->persist($first);
        $this->em->persist($second);
        try {
            $this->em->flush();
            self::fail('Expected unique failure');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            self::assertFalse($this->em->isOpen());
        }
        if ($first->id !== null) {
            self::assertNull($this->manager()->find(L2Country::class, $first->id));
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM modernorm_test_l2_countries WHERE name = 'duplicate'"));
    }
    public function testActiveTransactionBypassesWarmL2AndDoesNotPublishPrivateWrites(): void
    {
        $id = $this->seed();
        $this->warm($id);
        $other = $this->manager();
        $connection = $other->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement("UPDATE modernorm_test_l2_countries SET name = 'private' WHERE id = ?", [$id]);
        $other->getStatistics()->reset();
        self::assertSame('private', $other->find(L2Country::class, $id)->name);
        self::assertSame(1, $this->countSql($other));
        self::assertSame('original', $this->manager()->find(L2Country::class, $id)->name);
        $connection->rollBack();
        self::assertSame('original', $this->manager()->find(L2Country::class, $id)->name);
    }
    public function testDisabledAutoCommitBypassesCacheBeforeTheConnectionOpens(): void
    {
        $id = $this->seed();
        $this->warm($id);
        $other = $this->manager();
        $other->getConnection()->close();
        $other->getConnection()->setAutoCommit(false);
        self::assertSame('original', $other->find(L2Country::class, $id)->name);
        self::assertSame(1, $this->countSql($other));
        self::assertTrue($other->getConnection()->isTransactionActive());
        $other->getConnection()->setAutoCommit(true);
    }
    public function testOuterTransactionCachedWritesAreRejected(): void
    {
        $this->em->getConnection()->beginTransaction();
        $country = new L2Country();
        $this->em->persist($country);
        try {
            $this->em->flush();
            self::fail('Expected unsupported outer transaction');
        } catch (\LogicException $error) {
            self::assertStringContainsString('top-level ORM-owned', $error->getMessage());
        }
        self::assertFalse($this->em->isOpen());
        $this->em->getConnection()->rollBack();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_l2_countries'));
    }
    public function testExplicitEvictionAndRefreshAfterExternalWrite(): void
    {
        $id = $this->seed();
        $country = $this->warm($id);
        $this->em->getConnection()->executeStatement("UPDATE modernorm_test_l2_countries SET name = 'external' WHERE id = ?", [$id]);
        self::assertSame('original', $this->manager()->find(L2Country::class, $id)->name);
        $this->em->getCache()->evict(L2Country::class, (string) $id);
        self::assertSame('external', $this->manager()->find(L2Country::class, $id)->name);
        self::assertSame('original', $country->name);
        $this->em->refresh($country);
        self::assertSame('external', $country->name);
        self::assertFalse($this->em->getCache()->contains(L2Country::class, $id));
    }
    public function testCriteriaAndNativeQueriesDoNotUseL2QueryCaching(): void
    {
        $id = $this->seed();
        $this->warm($id);
        $other = $this->manager();
        self::assertCount(1, $other->getRepository(L2Country::class)->findBy(['name' => 'original']));
        self::assertSame(1, $this->countSql($other));
        $query = $other->createQueryBuilder()->fromEntity(L2Country::class, 'c')->getQuery();
        $query->getResult();
        self::assertSame(2, $this->countSql($other));
        self::assertFalse(method_exists($other->getCache(), 'getQueryCache'));
        self::assertFalse(method_exists($query, 'setCacheable'));
    }
    public function testUnsupportedStrategyAndAssociationGraphAreRejected(): void
    {
        try {
            $this->em->find(L2UnsupportedStrategy::class, 1);
            self::fail('Expected removed cache attribute');
        } catch (\ModernORM\Exception\MappingException $error) {
            self::assertStringContainsString('Cache was removed', $error->getMessage());
        }
        try {
            $this->em->find(L2UnsupportedGraph::class, 1);
            self::fail('Expected unsupported cache graph');
        } catch (\LogicException $error) {
            self::assertStringContainsString('without declared associations', $error->getMessage());
        }
    }
    public function testRegionEvictionDoesNotClearOtherRegionsOrSharedPoolData(): void
    {
        $id = $this->seed();
        $this->warm($id);
        $this->em->getConnection()->insert('modernorm_test_l2_units', ['id' => 'kg', 'name' => 'kilogram']);
        $this->em->find(L2Unit::class, 'kg');
        $this->pool->save($this->pool->getItem('unrelated')->set('keep'));
        $this->em->getCache()->evictAll(L2Country::class);
        self::assertFalse($this->em->getCache()->contains(L2Country::class, $id));
        self::assertTrue($this->em->getCache()->contains(L2Unit::class, 'kg'));
        self::assertSame('keep', $this->pool->getItem('unrelated')->get());
    }
    public function testStringIdsWithPunctuationDoNotCollide(): void
    {
        $ids = ['a:b', 'a/b', 'a_b', 'a b', '01', '1'];
        foreach ($ids as $id) {
            $this->em->getConnection()->insert('modernorm_test_l2_units', ['id' => $id, 'name' => $id]);
            $this->em->find(L2Unit::class, $id);
        }
        $other = $this->manager();
        foreach ($ids as $id) {
            self::assertSame($id, $other->find(L2Unit::class, $id)->name);
        }
        self::assertSame(0, $this->countSql($other));
    }
    public function testFilesystemPoolSurvivesNewAdaptersAndManagers(): void
    {
        $id = $this->seed();
        $dir = sys_get_temp_dir() . '/modernorm-l2-' . bin2hex(random_bytes(6));
        $this->directories[] = $dir;
        $first = $this->manager(new FilesystemAdapter('test', 0, $dir));
        $first->find(L2Country::class, $id);
        $second = $this->manager(new FilesystemAdapter('test', 0, $dir));
        self::assertSame('original', $second->find(L2Country::class, $id)->name);
        self::assertSame(0, $this->countSql($second));
    }
    public static function invalidEntries(): array
    {
        return [['class'], ['id'], ['shape'], ['signature'], ['object']];
    }
    #[DataProvider('invalidEntries')]
    public function testInvalidCacheEntriesFallBackToDatabase(string $kind): void
    {
        $id = $this->seed();
        $this->warm($id);
        $key = new \ModernORM\Cache\EntityCacheKey(L2Country::class, ['id' => $id]);
        $region = $this->em->getUnitOfWork()->getEntityPersister(L2Country::class)->getCacheRegion();
        $entry = $region->get($key);
        $data = $entry->data;
        if ($kind === 'id') {
            $data['id'] = 9999;
        }
        if ($kind === 'shape') {
            unset($data['name']);
        }
        if ($kind === 'object') {
            $data['changedAt'] = new \DateTime();
        }
        $region->put($key, new ReadOnlyEntityCacheEntry($kind === 'class' ? L2Unit::class : L2Country::class, $data, $kind === 'signature' ? 'old' : $entry->mappingSignature));
        $other = $this->manager();
        self::assertSame('original', $other->find(L2Country::class, $id)->name);
        self::assertSame(1, $this->countSql($other));
    }
    public function testMissingRowsAreNotNegativelyCached(): void
    {
        $this->em->getStatistics()->reset();
        self::assertNull($this->em->find(L2Country::class, 9999));
        self::assertNull($this->em->find(L2Country::class, 9999));
        self::assertSame(2, $this->countSql($this->em));
        self::assertFalse($this->em->getCache()->contains(L2Country::class, 9999));
    }
    public function testConcurrentPreCommitReaderCannotResurrectDeletedEntry(): void
    {
        $id = $this->seed();
        $country = $this->warm($id);
        $reader = $this->manager();
        $this->commitLogger->onCommit = function () use ($reader, $id): void {
            self::assertSame('original', $reader->find(L2Country::class, $id)->name);
            self::assertSame(1, $this->countSql($reader));
        };
        $this->em->remove($country);
        $this->em->flush();
        $this->commitLogger->onCommit = null;
        self::assertFalse($this->em->getCache()->contains(L2Country::class, $id));
        self::assertNull($this->manager()->find(L2Country::class, $id));
    }
    public function testRollbackAfterDeleteLeavesCommittedDatabaseStateReadable(): void
    {
        $id = $this->seed();
        $country = $this->warm($id);
        $this->commitLogger->onCommit = static function (): void {
            throw new \RuntimeException('abort before commit');
        };
        $this->em->remove($country);
        try {
            $this->em->flush();
            self::fail('Expected rollback');
        } catch (CacheSynchronizationException $error) {
            self::assertNull($error->databaseCommitted);
            self::assertSame('abort before commit', $error->getPrevious()->getMessage());
        }
        $this->commitLogger->onCommit = null;
        self::assertFalse($this->em->isOpen());
        self::assertSame('original', $this->manager()->find(L2Country::class, $id)->name);
    }
    public function testCacheFailureBeforeDeleteRollsBackAndDoesNotReportCommit(): void
    {
        $id = $this->seed();
        $country = $this->warm($id);
        $this->em->remove($country);
        $this->pool->failWrites = true;
        try {
            $this->em->flush();
            self::fail('Expected cache failure');
        } catch (\ModernORM\Exception\CacheException) {
            self::assertFalse($this->em->isOpen());
        }
        $this->pool->failWrites = false;
        self::assertSame('original', $this->manager()->find(L2Country::class, $id)->name);
    }
    public function testCallbackChangingTransactionNestingCannotBeReportedAsCommitted(): void
    {
        $this->em->getEventManager()->addEventListener([\ModernORM\Events::postPersist], new class ($this->em) {
            public function __construct(private EntityManager $em)
            {
            }
            public function postPersist(): void
            {
                $this->em->getConnection()->beginTransaction();
            }
        });
        $country = new L2Country();
        $this->em->persist($country);
        try {
            $this->em->flush();
            self::fail('Expected incomplete top-level commit rejection');
        } catch (CacheSynchronizationException $error) {
            self::assertNull($error->databaseCommitted);
        }
        self::assertFalse($this->em->isOpen());
        self::assertTrue($this->em->getConnection()->isTransactionActive());
        $this->em->getConnection()->rollBack();
        self::assertNull($this->manager()->find(L2Country::class, $country->id));
    }
    public function testCacheFailureAfterCommitReportsCommittedAndClosesManager(): void
    {
        $id = $this->seed();
        $country = $this->warm($id);
        $this->em->remove($country);
        $this->commitLogger->onCommit = function (): void {
            $this->pool->failWrites = true;
        };
        try {
            $this->em->flush();
            self::fail('Expected cache failure');
        } catch (CacheSynchronizationException $error) {
            self::assertTrue($error->databaseCommitted);
            self::assertFalse($this->em->isOpen());
        }
        $this->pool->failWrites = false;
        $this->commitLogger->onCommit = null;
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_l2_countries WHERE id = ?', [$id]));
        $this->em->getCache()->evictAll(L2Country::class);
        self::assertNull($this->manager()->find(L2Country::class, $id));
    }
    public function testClassTtlIsAppliedToPayloadsAndGenerationMetadataHasNoExpiration(): void
    {
        $id = $this->seed();
        $this->warm($id);
        $this->em->getConnection()->insert('modernorm_test_l2_units', ['id' => 'kg', 'name' => 'kilogram']);
        $this->manager()->find(L2Unit::class, 'kg');
        $seen = [];
        foreach ($this->pool->getValues() as $key => $unused) {
            $item = $this->pool->getItem($key);
            if ($item->get() instanceof ReadOnlyEntityCacheEntry) {
                $entry = $item->get();
                $seen[] = $entry->class;
                if ($entry->class === L2Country::class) {
                    self::assertGreaterThan(time() + 55, $item->getMetadata()['expiry']);
                    self::assertLessThanOrEqual(time() + 61, $item->getMetadata()['expiry']);
                } else {
                    self::assertArrayNotHasKey('expiry', $item->getMetadata());
                }
            } elseif (is_string($item->get())) {
                self::assertArrayNotHasKey('expiry', $item->getMetadata());
            }
        }
        self::assertContains(L2Country::class, $seen);
        self::assertContains(L2Unit::class, $seen);
    }

    public function testApplicationNamespacesIsolateReadsAndClassEviction(): void
    {
        $id = $this->seed();
        $this->warm($id);
        $other = $this->manager(namespace: 'second_application');
        self::assertSame('original', $other->find(L2Country::class, $id)->name);
        $this->em->getConnection()->executeStatement('UPDATE modernorm_test_l2_countries SET name = ? WHERE id = ?', ['external', $id]);
        $this->em->getCache()->evictAll(L2Country::class);
        self::assertSame('external', $this->manager()->find(L2Country::class, $id)->name);
        self::assertSame('original', $this->manager(namespace: 'second_application')->find(L2Country::class, $id)->name);
        $other->getCache()->evict(L2Country::class, $id);
        self::assertSame('external', $this->manager(namespace: 'second_application')->find(L2Country::class, $id)->name);
    }

    public function testEntityStatisticsIncludeExplicitInvalidationsAndReset(): void
    {
        $id = $this->seed();
        $this->cacheLogger->reset();
        $this->warm($id);
        $this->manager()->find(L2Country::class, $id);
        $this->em->getCache()->evict(L2Country::class, ['id' => $id]);
        $this->em->getCache()->evictAll(L2Country::class);
        self::assertSame(1, $this->cacheLogger->getHitCount(L2Country::class));
        self::assertSame(1, $this->cacheLogger->getMissCount(L2Country::class));
        self::assertSame(1, $this->cacheLogger->getPutCount(L2Country::class));
        self::assertSame(2, $this->cacheLogger->getEvictionCount(L2Country::class));
        self::assertSame(0, $this->cacheLogger->getEvictionCount(L2Unit::class));
        $this->cacheLogger->reset();
        self::assertSame(0, $this->cacheLogger->getHitCount());
        self::assertSame(0, $this->cacheLogger->getMissCount());
        self::assertSame(0, $this->cacheLogger->getPutCount());
        self::assertSame(0, $this->cacheLogger->getEvictionCount());
    }

    public function testCacheFacadeDoesNotChangeManagedIdentityAndIgnoresUncachedClasses(): void
    {
        $id = $this->seed();
        $country = $this->warm($id);
        $cache = $this->em->getCache();
        self::assertTrue($cache->contains(L2Country::class, (string) $id));
        $cache->evict(L2Country::class, $id);
        self::assertFalse($cache->contains(L2Country::class, $id));
        self::assertSame($country, $this->em->find(L2Country::class, $id));
        self::assertFalse($cache->contains(L2Invoice::class, 1));
        $cache->evict(L2Invoice::class, 1);
        $cache->evictAll(L2Invoice::class);
        $this->expectException(\InvalidArgumentException::class);
        $cache->evict(L2Country::class, ['wrong_field' => $id]);
    }
}
