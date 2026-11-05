<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use ModernORM\Bulk\BulkPolicy;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Events;
use ModernORM\Exception\BulkOperationException;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

enum BulkState: string { case READY = 'ready'; }

#[ORM\Entity, ORM\Table(name: 'modernorm_test_bulk_records'), ORM\HasLifecycleCallbacks]
class BulkRecord
{
    public static int $callbacks = 0;
    #[ORM\Id, ORM\Column, ORM\GeneratedValue] public ?int $id = null;
    #[ORM\Column(unique: true)] public string $name = 'pending';
    #[ORM\Column(name: 'quantity')] public int $amount = 0;
    #[ORM\Column(type: 'json')] public array $payload = [];
    #[ORM\Version, ORM\Column] public int $version = 1;
    #[ORM\Column(nullable: true)] public ?BulkState $state = null;
    #[ORM\Column(type: 'datetime_immutable', nullable: true)] public ?\DateTimeImmutable $created = null;
    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: BulkLine::class, batchSize: 8)] public Collection $lines;
    #[ORM\PrePersist, ORM\PreUpdate, ORM\PreRemove] public function callback(): void { ++self::$callbacks; }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_bulk_lines')]
class BulkLine
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToOne(targetEntity: BulkRecord::class, inversedBy: 'lines', batchSize: 8)] public BulkRecord $owner;
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_bulk_cached'), ORM\Immutable, ORM\Cacheable]
class BulkCached
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column] public string $name;
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_bulk_other'), ORM\Cacheable]
class BulkOther
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column] public string $name;
}

#[ORM\Entity, ORM\Table(name: '`modernorm_test_bulk_quoted`', schema: 'public')]
class BulkQuoted
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column(name: '`select`')] public string $value;
}

#[ORM\Entity, ORM\Cacheable]
class BulkUnsupportedCacheGraph
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToOne(targetEntity: BulkCached::class)] public BulkCached $target;
}

final class BulkPool extends ArrayAdapter
{
    public bool $failWrites = false;
    public function save(CacheItemInterface $item): bool { return !$this->failWrites && parent::save($item); }
}

final class BulkCommitLogger extends AbstractLogger
{
    public ?\Closure $onCommit = null;
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ((string) $message === 'Committing transaction' && $this->onCommit !== null) {
            $callback = $this->onCommit;
            $this->onCommit = null;
            $callback();
        }
    }
}

final class BulkOperationsTest extends TestCase
{
    private EntityManager $em;
    private BulkPool $pool;
    private BulkCommitLogger $logger;
    private array $managers = [];
    private array $metadata;

    private function manager(bool $l2 = false, bool $logger = false): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        if ($l2) {
            $config->setSecondLevelCache($this->pool);
        }
        $dbal = new DBALConfiguration();
        if ($logger) { $dbal->setMiddlewares([new Middleware($this->logger)]); }
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
        BulkRecord::$callbacks = 0;
        $this->pool = new BulkPool(storeSerialized: true);
        $this->logger = new BulkCommitLogger();
        $this->em = $this->manager(logger: true);
        $this->metadata = array_map($this->em->getClassMetadata(...), [BulkRecord::class, BulkLine::class, BulkCached::class, BulkOther::class, BulkQuoted::class]);
        $schema = new SchemaTool($this->em);
        $schema->dropSchema($this->metadata);
        $schema->createSchema($this->metadata);
        $connection = $this->em->getConnection();
        $connection->executeStatement("INSERT INTO modernorm_test_bulk_records (name, quantity, payload) VALUES ('first', 10, '{}'), ('second', 20, '{}')");
        $connection->executeStatement('INSERT INTO modernorm_test_bulk_lines (id, owner_id) VALUES (1, 1), (2, 2)');
        $connection->executeStatement("INSERT INTO modernorm_test_bulk_cached VALUES (1, 'old')");
        $connection->executeStatement("INSERT INTO modernorm_test_bulk_other VALUES (1, 'other')");
    }

    protected function tearDown(): void
    {
        $this->pool->failWrites = false;
        $this->logger->onCommit = null;
        foreach ($this->managers as $em) {
            $connection = $em->getConnection();
            if (!$connection->isAutoCommit()) { $connection->setAutoCommit(true); }
            while ($connection->isTransactionActive()) { $connection->rollBack(); }
        }
        (new SchemaTool($this->em))->dropSchema($this->metadata);
        foreach ($this->managers as $em) { $em->getConnection()->close(); }
    }

    public function testInsertAndUpsertReturnRawRowsWithoutManagingOrCallingCallbacks(): void
    {
        $bulk = $this->em->bulk();
        $result = $bulk->insert(BulkRecord::class, [
            ['name' => 'third', 'amount' => 30, 'payload' => ['a' => 1]],
            ['payload' => [], 'amount' => 40, 'name' => 'fourth'],
        ], BulkPolicy::BYPASS, returning: ['id', 'name', 'amount', 'payload']);
        self::assertSame(2, $result->affectedRows);
        self::assertCount(2, $result->returnedRows);
        self::assertTrue($result->databaseCommitted);
        self::assertSame(30, $result->returnedRows[0]['amount']);
        self::assertSame(['a' => 1], json_decode($result->returnedRows[0]['payload'], true));
        self::assertSame([], $this->em->getUnitOfWork()->getIdentityMap());
        $result = $bulk->upsert(BulkRecord::class, [
            ['name' => 'third', 'amount' => 31, 'payload' => []],
            ['name' => 'fifth', 'amount' => 50, 'payload' => []],
        ], ['name'], ['amount'], BulkPolicy::BYPASS, returning: ['name', 'amount']);
        self::assertSame(2, $result->affectedRows);
        self::assertSame(31, $result->returnedRows[0]['amount']);
        $nothing = $bulk->upsert(BulkRecord::class, [['name' => 'third', 'amount' => 99, 'payload' => []]], ['name'], [], BulkPolicy::BYPASS, returning: ['id']);
        self::assertSame(0, $nothing->affectedRows);
        self::assertSame([], $nothing->returnedRows);
        self::assertSame(0, BulkRecord::$callbacks);
    }

    public function testBypassPreservesIdentitySnapshotsPendingWorkAndQueues(): void
    {
        $first = $this->em->find(BulkRecord::class, 1);
        $second = $this->em->find(BulkRecord::class, 2);
        $first->amount = 99;
        $pending = new BulkRecord();
        $this->em->persist($pending);
        $this->em->remove($second);
        $snapshot = $this->em->getUnitOfWork()->getOriginalEntityData($first);
        $queue = new \ReflectionProperty($this->em->getUnitOfWork()->getLazyCollectionQueue(), 'pending');
        $beforeQueue = $queue->getValue($this->em->getUnitOfWork()->getLazyCollectionQueue());
        self::assertNotEmpty($beforeQueue);
        BulkRecord::$callbacks = 0;
        $result = $this->em->bulk()->update(BulkRecord::class, ['amount' => 30], 'id IN (:ids)', BulkPolicy::BYPASS, ['ids' => [1, 2]], ['ids' => ArrayParameterType::INTEGER]);
        self::assertSame(2, $result->affectedRows);
        self::assertSame([], $result->returnedRows);
        self::assertSame($first, $this->em->find(BulkRecord::class, 1));
        self::assertSame(99, $first->amount);
        self::assertSame($snapshot, $this->em->getUnitOfWork()->getOriginalEntityData($first));
        self::assertSame($beforeQueue, $queue->getValue($this->em->getUnitOfWork()->getLazyCollectionQueue()));
        self::assertTrue($this->em->getUnitOfWork()->isScheduledForInsert($pending));
        self::assertTrue($this->em->getUnitOfWork()->isScheduledForDelete($second));
        self::assertSame(0, BulkRecord::$callbacks);
        // Remove the child before the later ordinary ORM delete.
        $this->em->getConnection()->executeStatement('DELETE FROM modernorm_test_bulk_lines WHERE owner_id = 2');
        $this->em->flush();
        self::assertSame(99, (int) $this->em->getConnection()->fetchOne('SELECT quantity FROM modernorm_test_bulk_records WHERE id = 1'));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT version FROM modernorm_test_bulk_records WHERE id = 1'));
    }

    public function testClearDiscardsAllPendingWorkAndBothLazyQueuesEvenAtZeroRows(): void
    {
        $first = $this->em->find(BulkRecord::class, 1);
        $second = $this->em->find(BulkRecord::class, 2);
        $line = $this->em->find(BulkLine::class, 1);
        $this->em->getReference(BulkLine::class, 2);
        $first->name = 'dirty';
        $pending = new BulkRecord(); $this->em->persist($pending);
        $this->em->remove($second);
        $events = new class { public int $calls = 0; public function onClear(): void { ++$this->calls; } };
        $this->em->getEventManager()->addEventListener([Events::onClear], $events);
        $result = $this->em->bulk()->delete(BulkRecord::class, 'FALSE', BulkPolicy::CLEAR);
        self::assertSame(0, $result->affectedRows);
        self::assertSame(1, $events->calls);
        foreach ([$first, $second, $line, $pending] as $entity) { self::assertFalse($this->em->contains($entity)); }
        foreach ([$this->em->getUnitOfWork()->getLazyCollectionQueue(), $this->em->getProxyFactory()->getBatchQueue()] as $queue) {
            self::assertSame([], (new \ReflectionProperty($queue, 'pending'))->getValue($queue));
        }
        self::assertSame([], $this->em->getUnitOfWork()->getScheduledEntityInsertions());
        self::assertSame([], $this->em->getUnitOfWork()->getScheduledEntityDeletions());
        $this->em->flush();
        self::assertSame('first', $this->em->find(BulkRecord::class, 1)->name);
        self::assertSame('dirty', $first->name);
    }

    public function testSqlFailureRollsBackOwnedTransactionWithoutClearing(): void
    {
        $entity = $this->em->find(BulkRecord::class, 1);
        $entity->amount = 100;
        try {
            $this->em->bulk()->insert(BulkRecord::class, [
                ['name' => 'new', 'amount' => 1, 'payload' => []],
                ['name' => 'first', 'amount' => 2, 'payload' => []],
            ], BulkPolicy::CLEAR);
            self::fail('Expected unique constraint');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {}
        self::assertTrue($this->em->contains($entity));
        self::assertSame(100, $entity->amount);
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM modernorm_test_bulk_records WHERE name = 'new'"));
    }

    #[DataProvider('policies')]
    public function testOuterTransactionPolicyIsNotReversedByRollback(BulkPolicy $policy): void
    {
        $entity = $this->em->find(BulkRecord::class, 1);
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $result = $this->em->bulk()->update(BulkRecord::class, ['amount' => 45], 'id = :id', $policy, ['id' => 1]);
        self::assertFalse($result->databaseCommitted);
        self::assertTrue($connection->isTransactionActive());
        self::assertSame($policy === BulkPolicy::BYPASS, $this->em->contains($entity));
        $connection->rollBack();
        self::assertSame(10, $this->em->find(BulkRecord::class, 1)->amount);
        self::assertSame($policy === BulkPolicy::BYPASS, $this->em->contains($entity));
    }

    public function testMappedTypesAndQuotedSchemaColumns(): void
    {
        $result = $this->em->bulk()->insert(BulkRecord::class, [[
            'name' => 'typed', 'amount' => 1, 'payload' => ['valid' => true],
            'state' => BulkState::READY, 'created' => new \DateTimeImmutable('2026-01-02 03:04:05'),
        ]], BulkPolicy::BYPASS, returning: ['id', 'state', 'created']);
        self::assertSame('ready', $result->returnedRows[0]['state']);
        self::assertSame('2026-01-02 03:04:05', $result->returnedRows[0]['created']);
        $loaded = $this->em->find(BulkRecord::class, $result->returnedRows[0]['id']);
        self::assertSame(BulkState::READY, $loaded->state);
        self::assertInstanceOf(\DateTimeImmutable::class, $loaded->created);
        $result = $this->em->bulk()->insert(BulkQuoted::class, [['id' => 1, 'value' => 'first']], BulkPolicy::BYPASS, returning: ['value']);
        self::assertSame([['value' => 'first']], $result->returnedRows);
        $result = $this->em->bulk()->upsert(BulkQuoted::class, [['id' => 1, 'value' => 'changed']], ['id'], ['value'], BulkPolicy::BYPASS, returning: ['value']);
        self::assertSame([['value' => 'changed']], $result->returnedRows);
    }

    public function testDecoratorClearIsUsedByBulkExecutor(): void
    {
        $decorator = new class ($this->em) extends \ModernORM\Decorator\EntityManagerDecorator {
            public int $clears = 0;
            public function clear(): void { ++$this->clears; parent::clear(); }
        };
        $decorator->bulk()->delete(BulkRecord::class, 'FALSE', BulkPolicy::CLEAR);
        self::assertSame(1, $decorator->clears);
    }

    public function testOuterSqlFailureLeavesRecoveryToCallerAndKeepsManagedState(): void
    {
        $entity = $this->em->find(BulkRecord::class, 1);
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->em->bulk()->update(BulkRecord::class, ['name' => 'second'], 'id = 1', BulkPolicy::CLEAR);
            self::fail('Expected unique constraint');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {}
        self::assertTrue($connection->isTransactionActive());
        self::assertTrue($this->em->contains($entity));
        $connection->rollBack();
        self::assertSame('first', $this->em->find(BulkRecord::class, 1)->name);
    }

    public function testDisabledAutoCommitParticipatesWithoutCommitting(): void
    {
        $connection = $this->em->getConnection();
        $connection->setAutoCommit(false);
        $result = $this->em->bulk()->update(BulkRecord::class, ['amount' => 42], 'id = 1', BulkPolicy::BYPASS);
        self::assertFalse($result->databaseCommitted);
        self::assertTrue($connection->isTransactionActive());
        $connection->rollBack();
        self::assertSame(10, (int) $connection->fetchOne('SELECT quantity FROM modernorm_test_bulk_records WHERE id = 1'));
        $connection->setAutoCommit(true);
    }

    public static function policies(): array { return [[BulkPolicy::BYPASS], [BulkPolicy::CLEAR]]; }

    #[DataProvider('policies')]
    public function testL2RegionInvalidationIsIndependentOfPolicy(BulkPolicy $policy): void
    {
        $em = $this->manager(l2: true);
        $cached = $em->find(BulkCached::class, 1);
        $other = $em->find(BulkOther::class, 1);
        $this->pool->save($this->pool->getItem('unrelated')->set('safe'));
        $result = $em->bulk()->update(BulkCached::class, ['name' => 'new'], 'id = 1', $policy, returning: ['id', 'name']);
        self::assertSame('new', $result->returnedRows[0]['name']);
        self::assertFalse($em->getCache()->contains(BulkCached::class, 1));
        self::assertTrue($em->getCache()->contains(BulkOther::class, 1));
        self::assertSame('safe', $this->pool->getItem('unrelated')->get());
        self::assertSame('old', $cached->name);
        self::assertSame($policy === BulkPolicy::BYPASS, $em->contains($cached));
        self::assertSame('new', $this->manager(l2: true)->find(BulkCached::class, 1)->name);
        // Bulk SQL can modify Immutable entities; it does not change their UoW policy.
        self::assertSame('other', $other->name);
    }

    public function testBulkInsertAndDeleteInvalidateCachedRegions(): void
    {
        $em = $this->manager(l2: true);
        $em->find(BulkCached::class, 1);
        $em->bulk()->insert(BulkCached::class, [['id' => 2, 'name' => 'inserted']], BulkPolicy::BYPASS);
        self::assertFalse($em->getCache()->contains(BulkCached::class, 1));
        $em->find(BulkCached::class, 2);
        $result = $em->bulk()->delete(BulkCached::class, 'id = :id', BulkPolicy::CLEAR, ['id' => 2], returning: ['id']);
        self::assertSame([['id' => 2]], $result->returnedRows);
        self::assertNull($this->manager(l2: true)->find(BulkCached::class, 2));
    }

    public function testConcurrentPrecommitFillCannotSurvivePostcommitInvalidation(): void
    {
        $em = $this->manager(l2: true, logger: true);
        $em->find(BulkCached::class, 1);
        $region = $em->getUnitOfWork()->getEntityPersister(BulkCached::class)->getCacheRegion();
        $key = new \ModernORM\Cache\EntityCacheKey(BulkCached::class, ['id' => 1]);
        $entry = $region->get($key);
        $ticket = null;
        $this->logger->onCommit = function () use ($region, $key, &$ticket): void {
            // A second actual DB connection fills the precommit database value.
            self::assertSame('old', $this->manager(l2: true)->find(BulkCached::class, 1)->name);
            $ticket = $region->ticket($key);
        };
        $em->bulk()->update(BulkCached::class, ['name' => 'committed'], 'id = 1', BulkPolicy::BYPASS);
        $region->putAt($ticket, $entry); // An even later fill on the precommit generation.
        self::assertSame('committed', $this->manager(l2: true)->find(BulkCached::class, 1)->name);
    }

    public function testFailedCachedWriteDoesNotPublishAndDoesNotClear(): void
    {
        $em = $this->manager(l2: true);
        $entity = $em->find(BulkCached::class, 1);
        try {
            $em->bulk()->update(BulkCached::class, ['name' => null], 'id = 1', BulkPolicy::CLEAR);
            self::fail('Expected NOT NULL violation');
        } catch (\Doctrine\DBAL\Exception\NotNullConstraintViolationException) {}
        self::assertTrue($em->contains($entity));
        self::assertSame('old', $this->manager(l2: true)->find(BulkCached::class, 1)->name);
    }

    public function testCachedWritesRejectOuterTransactionBeforeDml(): void
    {
        $em = $this->manager(l2: true);
        $entity = $em->find(BulkCached::class, 1);
        $em->getConnection()->beginTransaction();
        try {
            $em->bulk()->delete(BulkCached::class, 'TRUE', BulkPolicy::CLEAR);
            self::fail('Expected outer transaction rejection');
        } catch (\LogicException $error) { self::assertStringContainsString('API-owned', $error->getMessage()); }
        self::assertTrue($em->contains($entity));
        self::assertTrue($em->getCache()->contains(BulkCached::class, 1));
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_bulk_cached'));
        $em->getConnection()->rollBack();
    }

    public function testAdditionalWriteSetInvalidatesTriggerSideEffects(): void
    {
        $em = $this->manager(l2: true);
        $em->find(BulkCached::class, 1);
        $connection = $em->getConnection();
        $connection->executeStatement("CREATE FUNCTION modernorm_test_bulk_trigger() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN UPDATE modernorm_test_bulk_cached SET name = NEW.name WHERE id = 1; RETURN NEW; END $$");
        try {
            $connection->executeStatement('CREATE TRIGGER bulk_side_effect AFTER UPDATE ON modernorm_test_bulk_records FOR EACH ROW EXECUTE FUNCTION modernorm_test_bulk_trigger()');
            $em->bulk()->update(BulkRecord::class, ['name' => 'triggered'], 'id = 1', BulkPolicy::BYPASS, affectedEntities: [BulkCached::class]);
            self::assertFalse($em->getCache()->contains(BulkCached::class, 1));
            self::assertSame('triggered', $this->manager(l2: true)->find(BulkCached::class, 1)->name);
        } finally {
            $connection->executeStatement('DROP TRIGGER IF EXISTS bulk_side_effect ON modernorm_test_bulk_records');
            $connection->executeStatement('DROP FUNCTION modernorm_test_bulk_trigger()');
        }
    }

    public function testCacheFailureBeforeSqlRollsBackWithoutClearing(): void
    {
        $em = $this->manager(l2: true);
        $entity = $em->find(BulkCached::class, 1);
        $this->pool->failWrites = true;
        try {
            $em->bulk()->delete(BulkCached::class, 'TRUE', BulkPolicy::CLEAR);
            self::fail('Expected cache failure');
        } catch (\ModernORM\Exception\CacheException) {}
        self::assertTrue($em->contains($entity));
        self::assertFalse($em->getConnection()->isTransactionActive());
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_bulk_cached'));
    }

    public function testCacheFailureAfterCommitReportsCommittedAndClosesManager(): void
    {
        $em = $this->manager(l2: true, logger: true);
        $this->logger->onCommit = function (): void { $this->pool->failWrites = true; };
        try {
            $em->bulk()->update(BulkCached::class, ['name' => 'committed'], 'id = 1', BulkPolicy::CLEAR);
            self::fail('Expected committed cache failure');
        } catch (BulkOperationException $error) { self::assertTrue($error->databaseCommitted); }
        self::assertFalse($em->isOpen());
        self::assertSame('committed', $this->em->getConnection()->fetchOne('SELECT name FROM modernorm_test_bulk_cached WHERE id = 1'));
    }

    public function testClearListenerFailureReportsCommitAndCannotKeepManagerOpen(): void
    {
        $listener = new class { public function onClear(): void { throw new \RuntimeException('listener failed'); } };
        $this->em->getEventManager()->addEventListener([Events::onClear], $listener);
        try {
            $this->em->bulk()->update(BulkRecord::class, ['amount' => 100], 'id = 1', BulkPolicy::CLEAR);
            self::fail('Expected listener failure');
        } catch (BulkOperationException $error) { self::assertTrue($error->databaseCommitted); }
        self::assertFalse($this->em->isOpen());
        self::assertSame(100, (int) $this->em->getConnection()->fetchOne('SELECT quantity FROM modernorm_test_bulk_records WHERE id = 1'));
        $this->em->getEventManager()->removeEventListener([Events::onClear], $listener);
    }

    public function testCommitFailureIsReportedAsUnknownWithoutRetry(): void
    {
        $this->logger->onCommit = static function (): void { throw new \RuntimeException('transport failure'); };
        try {
            $this->em->bulk()->update(BulkRecord::class, ['amount' => 100], 'id = 1', BulkPolicy::BYPASS);
            self::fail('Expected unknown commit');
        } catch (BulkOperationException $error) { self::assertNull($error->databaseCommitted); }
        self::assertFalse($this->em->isOpen());
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertSame(10, (int) $this->em->getConnection()->fetchOne('SELECT quantity FROM modernorm_test_bulk_records WHERE id = 1'));
    }

    public function testExplicitVersionGuardAndIncrementAreNotSynthesized(): void
    {
        $result = $this->em->bulk()->update(BulkRecord::class, ['amount' => 11], 'id = :id AND version = :v', BulkPolicy::BYPASS, ['id' => 1, 'v' => 99], returning: ['id', 'version']);
        self::assertSame(0, $result->affectedRows);
        $result = $this->em->bulk()->update(BulkRecord::class, ['amount' => 11], 'id = 1', BulkPolicy::BYPASS, returning: ['version']);
        self::assertSame(1, $result->returnedRows[0]['version']);
        $result = $this->em->bulk()->update(BulkRecord::class, ['version' => 2], 'id = 1 AND version = 1', BulkPolicy::BYPASS, returning: ['version']);
        self::assertSame(2, $result->returnedRows[0]['version']);
    }

    public static function invalidCalls(): array
    {
        return [
            'empty insert' => [static fn ($b) => $b->insert(BulkRecord::class, [], BulkPolicy::CLEAR)],
            'mismatched rows' => [static fn ($b) => $b->insert(BulkRecord::class, [['name' => 'a'], ['amount' => 1]], BulkPolicy::CLEAR)],
            'unknown field' => [static fn ($b) => $b->update(BulkRecord::class, ['missing' => 1], 'TRUE', BulkPolicy::CLEAR)],
            'association field' => [static fn ($b) => $b->update(BulkRecord::class, ['lines' => []], 'TRUE', BulkPolicy::CLEAR)],
            'empty values' => [static fn ($b) => $b->update(BulkRecord::class, [], 'TRUE', BulkPolicy::CLEAR)],
            'empty predicate' => [static fn ($b) => $b->delete(BulkRecord::class, '', BulkPolicy::CLEAR)],
            'positional params' => [static fn ($b) => $b->delete(BulkRecord::class, 'id = ?', BulkPolicy::CLEAR, [1])],
            'empty conflict' => [static fn ($b) => $b->upsert(BulkRecord::class, [['name' => 'a']], [], [], BulkPolicy::CLEAR)],
            'missing update field' => [static fn ($b) => $b->upsert(BulkRecord::class, [['name' => 'a']], ['name'], ['amount'], BulkPolicy::CLEAR)],
            'invalid returning' => [static fn ($b) => $b->delete(BulkRecord::class, 'TRUE', BulkPolicy::CLEAR, returning: ['missing'])],
        ];
    }

    #[DataProvider('invalidCalls')]
    public function testInvalidPlanDoesNotWriteOrClear(\Closure $operation): void
    {
        $entity = $this->em->find(BulkRecord::class, 1);
        try { $operation($this->em->bulk()); self::fail('Expected invalid plan'); }
        catch (\InvalidArgumentException) {}
        self::assertTrue($this->em->contains($entity));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_bulk_records'));
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }
    public function testUnsupportedAdditionalWriteSetFailsBeforeDmlOrClear(): void
    {
        $em = $this->manager(l2: true);
        $entity = $em->find(BulkRecord::class, 1);
        try {
            $em->bulk()->update(BulkRecord::class, ['amount' => 99], 'id = 1', BulkPolicy::CLEAR,
                affectedEntities: [BulkUnsupportedCacheGraph::class]);
            self::fail('Expected unsupported declared cache graph');
        } catch (\LogicException $error) { self::assertStringContainsString('without declared associations', $error->getMessage()); }
        self::assertTrue($em->contains($entity));
        self::assertSame(10, (int) $em->getConnection()->fetchOne('SELECT quantity FROM modernorm_test_bulk_records WHERE id = 1'));
        self::assertFalse($em->getConnection()->isTransactionActive());
    }
}
