<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use ModernORM\Cache\CacheWritePolicy;
use ModernORM\Cache\EntityCacheKey;
use ModernORM\Cache\Logging\StatisticsCacheLogger;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Exception\CacheSynchronizationException;
use ModernORM\Exception\OptimisticLockException;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemInterface;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

enum UpdateCacheState: string { case READY = 'ready'; case ACTIVE = 'active'; }

#[ORM\Entity, ORM\Table(name: 'modernorm_test_l2_update_records'), ORM\Cacheable(writePolicy: CacheWritePolicy::INVALIDATE), ORM\HasLifecycleCallbacks]
final class UpdateCacheRecord
{
    public static array $events = [];
    public static ?\Closure $onPostUpdate = null;
    #[ORM\Id, ORM\Column(length: 32)] public string $id;
    #[ORM\Column(unique: true)] public string $name;
    #[ORM\Column] public int $amount = 1;
    #[ORM\Column(type: 'json')] public array $payload = ['initial' => true];
    #[ORM\Column] public UpdateCacheState $state = UpdateCacheState::READY;
    #[ORM\Column(type: 'datetime')] public \DateTime $changedAt;
    #[ORM\Version, ORM\Column] public int $version = 1;
    public function __construct(string $id, string $name) { $this->id = $id; $this->name = $name; $this->changedAt = new \DateTime('2026-01-01'); }
    #[ORM\PreUpdate] public function preUpdate(): void { self::$events[] = 'pre:' . $this->id; }
    #[ORM\PostUpdate] public function postUpdate(): void
    {
        self::$events[] = 'post:' . $this->id;
        if (self::$onPostUpdate !== null) { (self::$onPostUpdate)($this); }
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_l2_update_immutable'), ORM\Immutable, ORM\Cacheable(writePolicy: CacheWritePolicy::INVALIDATE)]
final class UpdateCacheImmutable
{
    public function __construct(#[ORM\Id, ORM\Column] public int $id, #[ORM\Column] public string $name) {}
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_l2_update_owners')]
final class UpdateCacheOwner
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToOne(targetEntity: UpdateCacheRecord::class, batchSize: 2)]
    #[ORM\JoinColumn(name: 'record_id', referencedColumnName: 'id')] public UpdateCacheRecord $record;
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_l2_update_plain'), ORM\Cacheable(writePolicy: CacheWritePolicy::INVALIDATE)]
final class UpdateCachePlain
{
    public function __construct(#[ORM\Id, ORM\Column] public int $id, #[ORM\Column] public string $name) {}
}

final class UpdateCachePool extends ArrayAdapter
{
    public bool $failWrites = false;
    public int $failNextWrites = 0;
    public function save(CacheItemInterface $item): bool
    {
        if ($this->failNextWrites > 0) { --$this->failNextWrites; return false; }
        return !$this->failWrites && parent::save($item);
    }
}

final class UpdateCacheRecorder extends AbstractLogger
{
    public array $sql = [];
    public ?\Closure $onCommit = null;
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql'])) { $this->sql[] = $context['sql']; }
        if ((string) $message === 'Committing transaction' && $this->onCommit !== null) {
            $callback = $this->onCommit; $this->onCommit = null; $callback();
        }
    }
}

final class UpdateCacheCommitFault
{
    public ?string $mode = null;
}

final class UpdateCacheCommitMiddleware implements \Doctrine\DBAL\Driver\Middleware
{
    public function __construct(private readonly UpdateCacheCommitFault $fault) {}
    public function wrap(\Doctrine\DBAL\Driver $driver): \Doctrine\DBAL\Driver
    {
        return new class ($driver, $this->fault) extends \Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware {
            public function __construct(\Doctrine\DBAL\Driver $driver, private readonly UpdateCacheCommitFault $fault) { parent::__construct($driver); }
            public function connect(array $params): \Doctrine\DBAL\Driver\Connection
            {
                return new class (parent::connect($params), $this->fault) extends \Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware {
                    public function __construct(\Doctrine\DBAL\Driver\Connection $connection, private readonly UpdateCacheCommitFault $fault) { parent::__construct($connection); }
                    public function commit(): void
                    {
                        $mode = $this->fault->mode; $this->fault->mode = null;
                        if ($mode === 'before') { throw new \RuntimeException('commit failure before driver commit'); }
                        parent::commit();
                        if ($mode === 'after') { throw new \RuntimeException('lost commit acknowledgement'); }
                    }
                };
            }
        };
    }
}

final class UpdateL2CacheTest extends TestCase
{
    private EntityManager $em;
    private UpdateCachePool $pool;
    private StatisticsCacheLogger $cacheLogger;
    private UpdateCacheRecorder $recorder;
    private array $managers = [];
    private array $metadata;
    private UpdateCacheCommitFault $commitFault;

    private function manager(bool $record = false, ?\Psr\Cache\CacheItemPoolInterface $pool = null): EntityManager
    {
        $statistics = new Statistics(enabled: true);
        $dbal = new DBALConfiguration();
        $middlewares = [new Middleware($statistics)];
        if ($record) { $middlewares[] = new UpdateCacheCommitMiddleware($this->commitFault); $middlewares[] = new LoggingMiddleware($this->recorder); }
        $dbal->setMiddlewares($middlewares);
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $config->setSecondLevelCache($pool ?? $this->pool, logger: $this->cacheLogger);
        $em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'serverVersion' => '17',
            'host' => getenv('PGHOST') ?: '127.0.0.1', 'port' => getenv('PGPORT') ?: 5432,
            'user' => getenv('PGUSER') ?: 'postgres', 'password' => getenv('PGPASSWORD') ?: 'postgres',
            'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $dbal), $config);
        $this->managers[] = $em;
        return $em;
    }

    protected function setUp(): void
    {
        UpdateCacheRecord::$events = []; UpdateCacheRecord::$onPostUpdate = null;
        $this->commitFault = new UpdateCacheCommitFault();
        $this->pool = new UpdateCachePool(storeSerialized: true);
        $this->cacheLogger = new StatisticsCacheLogger(); $this->recorder = new UpdateCacheRecorder();
        $this->em = $this->manager(record: true);
        $this->metadata = array_map($this->em->getClassMetadata(...), [UpdateCacheRecord::class, UpdateCacheImmutable::class, UpdateCacheOwner::class, UpdateCachePlain::class]);
        $schema = new SchemaTool($this->em); $schema->dropSchema($this->metadata); $schema->createSchema($this->metadata);
        $this->em->persist(new UpdateCacheRecord('01', 'first'));
        $this->em->persist(new UpdateCacheRecord('1', 'second'));
        $this->em->persist(new UpdateCacheImmutable(1, 'immutable'));
        $this->em->persist(new UpdateCachePlain(1, 'plain'));
        $this->em->flush(); $this->em->clear();
        $this->em->getConnection()->insert('modernorm_test_l2_update_owners', ['id' => 1, 'record_id' => '01']);
        $this->em->getConnection()->insert('modernorm_test_l2_update_owners', ['id' => 2, 'record_id' => '1']);
        $this->reset();
    }

    protected function tearDown(): void
    {
        $this->pool->failWrites = false; $this->pool->failNextWrites = 0;
        $this->recorder->onCommit = null; $this->commitFault->mode = null;
        UpdateCacheRecord::$onPostUpdate = null; UpdateCacheRecord::$events = [];
        foreach ($this->managers as $em) {
            $connection = $em->getConnection();
            if (!$connection->isAutoCommit()) { $connection->setAutoCommit(true); }
            while ($connection->isTransactionActive()) { $connection->rollBack(); }
        }
        (new SchemaTool($this->em))->dropSchema($this->metadata);
        foreach ($this->managers as $em) { $em->getConnection()->close(); }
    }

    private function reset(): void
    {
        $this->em->getStatistics()->reset(); $this->cacheLogger->reset(); $this->recorder->sql = []; UpdateCacheRecord::$events = [];
    }

    private function updateCount(): int { return count(array_filter($this->recorder->sql, static fn ($sql) => str_starts_with($sql, 'UPDATE '))); }

    public function testUpdateInvalidatesOnlyItsKeyAndKeepsNormalVersionAndSnapshots(): void
    {
        $record = $this->em->find(UpdateCacheRecord::class, '01');
        $this->em->find(UpdateCacheRecord::class, '1'); $this->reset();
        $record->name = 'updated'; $record->amount = 9; $record->payload = ['changed' => [1, 2]];
        $record->state = UpdateCacheState::ACTIVE; $record->changedAt = new \DateTime('2026-05-01');
        $this->em->flush();
        self::assertSame(1, $this->updateCount());
        self::assertSame(2, $record->version);
        self::assertSame(['pre:01', 'post:01'], UpdateCacheRecord::$events);
        self::assertSame($record, $this->em->find(UpdateCacheRecord::class, '01'));
        self::assertFalse($this->em->getCache()->contains(UpdateCacheRecord::class, '01'));
        self::assertTrue($this->em->getCache()->contains(UpdateCacheRecord::class, '1'));
        self::assertSame(0, $this->cacheLogger->getPutCount());
        self::assertSame(2, $this->cacheLogger->getEvictionCount());
        $this->em->flush(); self::assertSame(1, $this->updateCount());
        $other = $this->manager(); $fresh = $other->find(UpdateCacheRecord::class, '01');
        self::assertSame('updated', $fresh->name); self::assertSame(9, $fresh->amount);
        self::assertSame(2, $fresh->version); self::assertSame(['changed' => [1, 2]], $fresh->payload);
        self::assertSame(UpdateCacheState::ACTIVE, $fresh->state); self::assertSame('2026-05-01', $fresh->changedAt->format('Y-m-d'));
        self::assertSame(1, $other->getStatistics()->snapshot()->sqlCount);
        $warm = $this->manager(); self::assertSame(2, $warm->find(UpdateCacheRecord::class, '01')->version);
        self::assertSame(0, $warm->getStatistics()->snapshot()->sqlCount);
    }

    public function testPostUpdateMutationsAreNotPublishedAsDatabaseState(): void
    {
        $record = $this->em->find(UpdateCacheRecord::class, '01'); $record->name = 'database';
        UpdateCacheRecord::$onPostUpdate = static function (UpdateCacheRecord $entity): void { $entity->name = 'callback'; };
        $this->em->flush();
        self::assertSame('callback', $record->name);
        self::assertFalse($this->em->getCache()->contains(UpdateCacheRecord::class, '01'));
        self::assertSame('database', $this->manager()->find(UpdateCacheRecord::class, '01')->name);
    }

    public function testStaleWriterRollsBackAndNeverPublishesItsValues(): void
    {
        $winner = $this->em->find(UpdateCacheRecord::class, '01');
        $loser = $this->manager(); $stale = $loser->find(UpdateCacheRecord::class, '01');
        $winner->name = 'winner'; $this->em->flush();
        self::assertSame('winner', $this->manager()->find(UpdateCacheRecord::class, '01')->name);
        $stale->name = 'loser';
        try { $loser->flush(); self::fail('Expected optimistic lock conflict'); }
        catch (OptimisticLockException) {}
        self::assertFalse($loser->isOpen());
        self::assertFalse($loser->getConnection()->isTransactionActive());
        self::assertFalse($this->em->getCache()->contains(UpdateCacheRecord::class, '01'));
        $fresh = $this->manager()->find(UpdateCacheRecord::class, '01');
        self::assertSame('winner', $fresh->name); self::assertSame(2, $fresh->version);
    }

    public function testFailureOnSecondUpdateRollsBackFirstUpdateAndBothVersions(): void
    {
        $first = $this->em->find(UpdateCacheRecord::class, '01'); $second = $this->em->find(UpdateCacheRecord::class, '1');
        $first->name = 'duplicate'; $second->name = 'duplicate';
        try { $this->em->flush(); self::fail('Expected unique constraint'); }
        catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {}
        self::assertFalse($this->em->isOpen()); self::assertFalse($this->em->getConnection()->isTransactionActive());
        $fresh = $this->manager();
        self::assertSame('first', $fresh->find(UpdateCacheRecord::class, '01')->name);
        self::assertSame(1, $fresh->find(UpdateCacheRecord::class, '01')->version);
        self::assertSame('second', $fresh->find(UpdateCacheRecord::class, '1')->name);
        self::assertSame(1, $fresh->find(UpdateCacheRecord::class, '1')->version);
    }

    public function testPostUpdateFailureRollsBackWithoutPublishingChangedVersion(): void
    {
        $record = $this->em->find(UpdateCacheRecord::class, '01'); $record->name = 'rollback';
        UpdateCacheRecord::$onPostUpdate = static function (): void { throw new \RuntimeException('callback failed'); };
        try { $this->em->flush(); self::fail('Expected callback failure'); }
        catch (\RuntimeException $error) { self::assertSame('callback failed', $error->getMessage()); }
        self::assertFalse($this->em->isOpen());
        $fresh = $this->manager()->find(UpdateCacheRecord::class, '01');
        self::assertSame('first', $fresh->name); self::assertSame(1, $fresh->version);
    }

    public function testOuterTransactionUpdateIsRejectedBeforeDmlOrEviction(): void
    {
        $record = $this->em->find(UpdateCacheRecord::class, '01'); $record->name = 'outer';
        $connection = $this->em->getConnection(); $connection->beginTransaction(); $this->reset();
        try { $this->em->flush(); self::fail('Expected outer transaction rejection'); }
        catch (\LogicException $error) { self::assertStringContainsString('top-level ORM-owned', $error->getMessage()); }
        self::assertSame(0, $this->updateCount()); self::assertSame(0, $this->cacheLogger->getEvictionCount());
        self::assertFalse($this->em->isOpen()); self::assertTrue($connection->isTransactionActive());
        $connection->rollBack();
        self::assertSame('first', $this->manager()->find(UpdateCacheRecord::class, '01')->name);
    }

    public function testDisabledAutoCommitRejectsUpdate(): void
    {
        $record = $this->em->find(UpdateCacheRecord::class, '01'); $record->name = 'disabled';
        $connection = $this->em->getConnection(); $connection->setAutoCommit(false); $this->reset();
        try { $this->em->flush(); self::fail('Expected disabled auto-commit rejection'); }
        catch (\LogicException $error) { self::assertStringContainsString('top-level ORM-owned', $error->getMessage()); }
        self::assertSame(0, $this->updateCount());
        $connection->rollBack(); $connection->setAutoCommit(true);
        self::assertSame('first', $this->manager()->find(UpdateCacheRecord::class, '01')->name);
    }

    public function testNestedTransactionFromCallbackCannotMasqueradeAsCommit(): void
    {
        $record = $this->em->find(UpdateCacheRecord::class, '01'); $record->name = 'pending';
        $connection = $this->em->getConnection();
        UpdateCacheRecord::$onPostUpdate = static function () use ($connection): void { $connection->beginTransaction(); };
        try { $this->em->flush(); self::fail('Expected incomplete transaction completion'); }
        catch (CacheSynchronizationException $error) { self::assertNull($error->databaseCommitted); }
        self::assertFalse($this->em->isOpen()); self::assertTrue($connection->isTransactionActive());
        $connection->rollBack();
        self::assertSame('first', $this->manager()->find(UpdateCacheRecord::class, '01')->name);
    }

    public function testPreWriteCacheFailureDoesNotExecuteUpdate(): void
    {
        $record = $this->em->find(UpdateCacheRecord::class, '01'); $record->name = 'not-written';
        $this->reset(); $this->pool->failWrites = true;
        try { $this->em->flush(); self::fail('Expected cache failure'); }
        catch (\ModernORM\Exception\CacheException) {}
        $this->pool->failWrites = false;
        self::assertSame(0, $this->updateCount()); self::assertFalse($this->em->isOpen());
        self::assertSame('first', $this->manager()->find(UpdateCacheRecord::class, '01')->name);
    }

    public function testPrecommitReaderAndLateFillCannotResurrectOldVersion(): void
    {
        $record = $this->em->find(UpdateCacheRecord::class, '01');
        $region = $this->em->getUnitOfWork()->getEntityPersister(UpdateCacheRecord::class)->getCacheRegion();
        $key = new EntityCacheKey(UpdateCacheRecord::class, ['id' => '01']); $ticket = $entry = null;
        $this->recorder->onCommit = function () use ($region, $key, &$ticket, &$entry): void {
            $reader = $this->manager();
            self::assertNotSame($this->em->getConnection()->getNativeConnection(), $reader->getConnection()->getNativeConnection());
            self::assertSame('first', $reader->find(UpdateCacheRecord::class, '01')->name);
            self::assertSame(1, $reader->find(UpdateCacheRecord::class, '01')->version);
            $ticket = $region->ticket($key); $entry = $region->get($key);
        };
        $record->name = 'committed'; $this->em->flush();
        self::assertFalse($this->em->getCache()->contains(UpdateCacheRecord::class, '01'));
        $region->putAt($ticket, $entry);
        self::assertFalse($this->em->getCache()->contains(UpdateCacheRecord::class, '01'));
        $fresh = $this->manager()->find(UpdateCacheRecord::class, '01');
        self::assertSame('committed', $fresh->name); self::assertSame(2, $fresh->version);
    }

    public function testPostCommitCacheFailureReportsCommitAndRequiresCacheRecovery(): void
    {
        $record = $this->em->find(UpdateCacheRecord::class, '01'); $record->name = 'committed'; $this->reset();
        $this->recorder->onCommit = function (): void {
            self::assertSame('first', $this->manager()->find(UpdateCacheRecord::class, '01')->name);
            $this->pool->failWrites = true;
        };
        try { $this->em->flush(); self::fail('Expected committed cache failure'); }
        catch (CacheSynchronizationException $error) { self::assertTrue($error->databaseCommitted); }
        $this->pool->failWrites = false;
        self::assertFalse($this->em->isOpen()); self::assertSame(1, $this->updateCount());
        self::assertSame('committed', $this->em->getConnection()->fetchOne("SELECT name FROM modernorm_test_l2_update_records WHERE id = '01'"));
        // A failed post-commit invalidation is not hidden as a successful recovery.
        self::assertSame('first', $this->manager()->find(UpdateCacheRecord::class, '01')->name);
        $this->em->getCache()->evict(UpdateCacheRecord::class, '01');
        $fresh = $this->manager()->find(UpdateCacheRecord::class, '01');
        self::assertSame('committed', $fresh->name); self::assertSame(2, $fresh->version);
    }

    public function testImmutableStillIgnoresUpdatesButAllowsInsertAndDelete(): void
    {
        $record = $this->em->find(UpdateCacheImmutable::class, 1); $record->name = 'ignored'; $this->reset();
        $this->em->flush();
        self::assertSame(0, $this->updateCount()); self::assertSame(0, $this->cacheLogger->getEvictionCount());
        self::assertSame('immutable', $this->manager()->find(UpdateCacheImmutable::class, 1)->name);
        $new = new UpdateCacheImmutable(2, 'inserted'); $this->em->persist($new); $this->em->flush();
        self::assertSame('inserted', $this->manager()->find(UpdateCacheImmutable::class, 2)->name);
        $this->em->remove($new); $this->em->flush();
        self::assertNull($this->manager()->find(UpdateCacheImmutable::class, 2));
    }

    public function testAssignedPrimaryKeyChangeIsRejectedBeforeDml(): void
    {
        $record = $this->em->find(UpdateCacheRecord::class, '01'); $record->id = 'changed'; $record->name = 'changed'; $this->reset();
        try { $this->em->flush(); self::fail('Expected identifier-change rejection'); }
        catch (\LogicException $error) { self::assertStringContainsString('primary key changes', $error->getMessage()); }
        self::assertSame(0, $this->updateCount());
        self::assertSame('first', $this->manager()->find(UpdateCacheRecord::class, '01')->name);
    }

    public function testUnversionedUpdateUsesTheSameInvalidationPolicy(): void
    {
        $record = $this->em->find(UpdateCachePlain::class, 1); $record->name = 'updated';
        $this->em->flush();
        self::assertFalse($this->em->getCache()->contains(UpdateCachePlain::class, 1));
        self::assertSame('updated', $this->manager()->find(UpdateCachePlain::class, 1)->name);
    }

    public function testUpdatedEntityCanBeALazyBatchMissAlongsideUnchangedCacheHit(): void
    {
        $record = $this->em->find(UpdateCacheRecord::class, '01'); $this->em->find(UpdateCacheRecord::class, '1');
        $record->name = 'updated'; $this->em->flush();
        $reader = $this->manager(); $owners = $reader->getRepository(UpdateCacheOwner::class)->findBy([], ['id' => 'ASC']);
        $reader->getStatistics()->reset(); $this->cacheLogger->reset();
        self::assertSame('updated', $owners[0]->record->name); self::assertSame(2, $owners[0]->record->version);
        self::assertFalse($reader->isUninitializedObject($owners[1]->record));
        self::assertSame('second', $owners[1]->record->name);
        self::assertSame(1, $reader->getStatistics()->snapshot()->sqlCount);
        self::assertSame(1, $reader->getStatistics()->snapshot()->batch->cacheHits);
        self::assertSame(1, $reader->getStatistics()->snapshot()->batch->cacheMisses);
        self::assertSame(1, $this->cacheLogger->getPutCount());
    }
    public function testDatabaseTriggerValuesAreReadFromSqlInsteadOfPublishedFromManagedObject(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement("CREATE FUNCTION modernorm_test_l2_update_trigger() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN NEW.amount := NEW.amount + 100; RETURN NEW; END $$");
        try {
            $connection->executeStatement('CREATE TRIGGER cache_update_value BEFORE UPDATE ON modernorm_test_l2_update_records FOR EACH ROW EXECUTE FUNCTION modernorm_test_l2_update_trigger()');
            $record = $this->em->find(UpdateCacheRecord::class, '01'); $record->amount = 2;
            $this->em->flush();
            self::assertSame(2, $record->amount);
            self::assertFalse($this->em->getCache()->contains(UpdateCacheRecord::class, '01'));
            $fresh = $this->manager()->find(UpdateCacheRecord::class, '01');
            self::assertSame(102, $fresh->amount); self::assertSame(2, $fresh->version);
        } finally {
            $connection->executeStatement('DROP TRIGGER IF EXISTS cache_update_value ON modernorm_test_l2_update_records');
            $connection->executeStatement('DROP FUNCTION modernorm_test_l2_update_trigger()');
        }
    }

    public function testDeleteInvalidatesDatabaseIdentifierEvenIfLocalIdWasChanged(): void
    {
        $record = new UpdateCacheRecord('delete-me', 'deletable');
        $this->em->persist($record); $this->em->flush(); $this->em->clear();
        $record = $this->em->find(UpdateCacheRecord::class, 'delete-me');
        $record->id = 'local-only'; $this->em->remove($record); $this->em->flush();
        self::assertFalse($this->em->getCache()->contains(UpdateCacheRecord::class, 'delete-me'));
        self::assertNull($this->manager()->find(UpdateCacheRecord::class, 'delete-me'));
    }
    public static function commitOutcomes(): array { return [['before', 'first', 'plain'], ['after', 'updated', 'updated-plain']]; }

    #[DataProvider('commitOutcomes')]
    public function testCommitErrorsReportUnknownAndInvalidateEveryAffectedClass(string $mode, string $expectedRecord, string $expectedPlain): void
    {
        $record = $this->em->find(UpdateCacheRecord::class, '01');
        $plain = $this->em->find(UpdateCachePlain::class, 1);
        $record->name = 'updated'; $plain->name = 'updated-plain';
        $this->recorder->onCommit = function (): void {
            $reader = $this->manager();
            self::assertSame('first', $reader->find(UpdateCacheRecord::class, '01')->name);
            self::assertSame('plain', $reader->find(UpdateCachePlain::class, 1)->name);
        };
        $this->commitFault->mode = $mode;
        try { $this->em->flush(); self::fail('Expected uncertain commit'); }
        catch (CacheSynchronizationException $error) {
            self::assertNull($error->databaseCommitted);
            self::assertNull($error->cacheFailure);
            self::assertStringContainsString($mode === 'before' ? 'before driver commit' : 'lost commit acknowledgement', $error->getPrevious()->getMessage());
        }
        self::assertFalse($this->em->isOpen()); self::assertFalse($this->em->getConnection()->isConnected());
        self::assertFalse($this->em->getCache()->contains(UpdateCacheRecord::class, '01'));
        self::assertFalse($this->em->getCache()->contains(UpdateCachePlain::class, 1));
        $reader = $this->manager();
        self::assertSame($expectedRecord, $reader->find(UpdateCacheRecord::class, '01')->name);
        self::assertSame($expectedPlain, $reader->find(UpdateCachePlain::class, 1)->name);
    }

    public function testUncertainCommitAndFailedCacheRecoveryPreserveBothFailures(): void
    {
        $record = $this->em->find(UpdateCacheRecord::class, '01'); $record->name = 'committed';
        $this->recorder->onCommit = function (): void {
            self::assertSame('first', $this->manager()->find(UpdateCacheRecord::class, '01')->name);
            $this->pool->failWrites = true;
        };
        $this->commitFault->mode = 'after';
        try { $this->em->flush(); self::fail('Expected commit/cache failure'); }
        catch (CacheSynchronizationException $error) {
            self::assertNull($error->databaseCommitted);
            self::assertSame('lost commit acknowledgement', $error->getPrevious()->getMessage());
            self::assertInstanceOf(\ModernORM\Exception\CacheException::class, $error->cacheFailure);
        }
        $this->pool->failWrites = false;
        self::assertFalse($this->em->isOpen());
        self::assertSame('first', $this->manager()->find(UpdateCacheRecord::class, '01')->name);
        $this->em->getCache()->evict(UpdateCacheRecord::class, '01');
        self::assertSame('committed', $this->manager()->find(UpdateCacheRecord::class, '01')->name);
    }

    public function testDeferredConstraintCommitFailureIsNotReportedAsKnownRollback(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('ALTER TABLE modernorm_test_l2_update_plain ADD CONSTRAINT modernorm_test_l2_update_deferred UNIQUE (name) DEFERRABLE INITIALLY DEFERRED');
        $connection->insert('modernorm_test_l2_update_plain', ['id' => 2, 'name' => 'other']);
        $record = $this->em->find(UpdateCachePlain::class, 1); $record->name = 'other';
        try { $this->em->flush(); self::fail('Expected commit constraint failure'); }
        catch (CacheSynchronizationException $error) {
            self::assertNull($error->databaseCommitted);
            self::assertInstanceOf(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class, $error->getPrevious());
        }
        self::assertFalse($this->em->isOpen()); self::assertFalse($connection->isConnected());
        self::assertFalse($this->em->getCache()->contains(UpdateCachePlain::class, 1));
        self::assertSame('plain', $this->manager()->find(UpdateCachePlain::class, 1)->name);
    }
    public function testPostCommitFailureDoesNotSkipOtherKeysOrOtherCachedClasses(): void
    {
        $first = $this->em->find(UpdateCacheRecord::class, '01');
        $second = $this->em->find(UpdateCacheRecord::class, '1');
        $plain = $this->em->find(UpdateCachePlain::class, 1);
        $first->name = 'new-first'; $second->name = 'new-second'; $plain->name = 'new-plain';
        $this->recorder->onCommit = function (): void {
            $reader = $this->manager();
            self::assertSame('first', $reader->find(UpdateCacheRecord::class, '01')->name);
            self::assertSame('second', $reader->find(UpdateCacheRecord::class, '1')->name);
            self::assertSame('plain', $reader->find(UpdateCachePlain::class, 1)->name);
            $this->pool->failNextWrites = 1;
        };
        try { $this->em->flush(); self::fail('Expected post-commit invalidation failure'); }
        catch (CacheSynchronizationException $error) { self::assertTrue($error->databaseCommitted); }
        $cached = [$this->em->getCache()->contains(UpdateCacheRecord::class, '01'),
            $this->em->getCache()->contains(UpdateCacheRecord::class, '1'), $this->em->getCache()->contains(UpdateCachePlain::class, 1)];
        self::assertSame(1, array_sum(array_map('intval', $cached)));
        self::assertFalse($this->em->isOpen());
        $this->em->getCache()->evictAll(UpdateCacheRecord::class); $this->em->getCache()->evictAll(UpdateCachePlain::class);
        $reader = $this->manager();
        self::assertSame('new-first', $reader->find(UpdateCacheRecord::class, '01')->name);
        self::assertSame('new-second', $reader->find(UpdateCacheRecord::class, '1')->name);
        self::assertSame('new-plain', $reader->find(UpdateCachePlain::class, 1)->name);
    }
    public function testFilesystemAdaptersShareUpdateInvalidationAndFreshPayloads(): void
    {
        $directory = sys_get_temp_dir() . '/modernorm-update-l2-' . bin2hex(random_bytes(6));
        $adapter = static fn () => new \Symfony\Component\Cache\Adapter\FilesystemAdapter('update_l2', 0, $directory);
        try {
            $writer = $this->manager(pool: $adapter());
            $record = $writer->find(UpdateCacheRecord::class, '01'); $record->name = 'filesystem';
            $writer->flush();
            self::assertFalse($writer->getCache()->contains(UpdateCacheRecord::class, '01'));
            $reader = $this->manager(pool: $adapter());
            self::assertSame('filesystem', $reader->find(UpdateCacheRecord::class, '01')->name);
            self::assertSame(2, $reader->find(UpdateCacheRecord::class, '01')->version);
            self::assertSame(1, $reader->getStatistics()->snapshot()->sqlCount);
            $warm = $this->manager(pool: $adapter());
            self::assertSame('filesystem', $warm->find(UpdateCacheRecord::class, '01')->name);
            self::assertSame(0, $warm->getStatistics()->snapshot()->sqlCount);
        } finally {
            if (is_dir($directory)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                }
                rmdir($directory);
            }
        }
    }
}
