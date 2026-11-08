<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use ModernORM\Behavior\SoftDeleteTrait;
use ModernORM\Behavior\TimestampableTrait;
use ModernORM\Bulk\BulkPolicy;
use ModernORM\Cache\CacheWritePolicy;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Exception\BulkOperationException;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Statistics;
use ModernORM\Statistics\BulkOperation;
use ModernORM\Statistics\Middleware;
use ModernORM\Statistics\OperationEvent;
use ModernORM\Statistics\OperationKind;
use ModernORM\Statistics\OperationObserver;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

trait BulkSoftFields
{
    use SoftDeleteTrait;
    use TimestampableTrait;

    public static int $callbacks = 0;
    #[ORM\Id, ORM\Column(type: 'integer')] public int $id;
    #[ORM\Column] public string $code;
    #[ORM\Version, ORM\Column(type: 'integer')] public int $version = 1;
    #[ORM\PreUpdate, ORM\PostUpdate, ORM\PreRemove, ORM\PostRemove]
    public function recordCallback(): void
    {
        ++self::$callbacks;
    }
}

#[ORM\Entity, ORM\SoftDelete, ORM\HasLifecycleCallbacks, ORM\Table(name: 'item')]
#[ORM\UniqueConstraint(name: 'bulk_soft_active_code', columns: ['code'], options: ['where' => 'deleted_at IS NULL'])]
class BulkSoftItem
{
    use BulkSoftFields;
}

#[ORM\Entity, ORM\SoftDelete, ORM\HasLifecycleCallbacks, ORM\Table(name: 'cached')]
#[ORM\Cacheable(writePolicy: CacheWritePolicy::INVALIDATE)]
class BulkSoftCachedItem
{
    use BulkSoftFields;
}

#[ORM\Entity, ORM\Table(name: 'plain')]
class BulkSoftPlainItem
{
    #[ORM\Id, ORM\Column(type: 'integer')] public int $id;
}

final class BulkSoftPool extends ArrayAdapter
{
    public bool $failWrites = false;
    public function save(CacheItemInterface $item): bool
    {
        return !$this->failWrites && parent::save($item);
    }
}

final class BulkSoftCommitLogger extends AbstractLogger
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

final class BulkSoftObserver implements OperationObserver
{
    public array $events = [];
    public function completed(OperationEvent $event): void
    {
        if ($event->kind === OperationKind::BULK) {
            $this->events[] = $event;
        }
    }
}

final class BulkSoftDeleteTest extends TestCase
{
    private EntityManager $em;
    private BulkSoftPool $pool;
    private BulkSoftCommitLogger $logger;
    private BulkSoftObserver $observer;
    private string $schema;
    private array $managers = [];

    private function manager(): EntityManager
    {
        $statistics = new Statistics(true, $this->observer);
        $dbal = new DbalConfiguration();
        $dbal->setMiddlewares([new Middleware($statistics), new LoggingMiddleware($this->logger)]);
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'serverVersion' => '17', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $dbal);
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $config->setSecondLevelCache($this->pool, $this->schema);
        $em = new EntityManager($connection, $config);
        foreach ([BulkSoftItem::class => 'item', BulkSoftCachedItem::class => 'cached', BulkSoftPlainItem::class => 'plain'] as $class => $table) {
            $em->getClassMetadata($class)->setPrimaryTable(['name' => $table, 'schema' => $this->schema]);
        }
        $this->managers[] = $em;
        return $em;
    }

    protected function setUp(): void
    {
        BulkSoftItem::$callbacks = BulkSoftCachedItem::$callbacks = 0;
        $this->pool = new BulkSoftPool(storeSerialized: true);
        $this->logger = new BulkSoftCommitLogger();
        $this->observer = new BulkSoftObserver();
        $this->schema = 'modernorm_bulk_sd_' . bin2hex(random_bytes(5));
        $this->em = $this->manager();
        (new SchemaTool($this->em))->createSchema(array_map($this->em->getClassMetadata(...), [BulkSoftItem::class, BulkSoftCachedItem::class, BulkSoftPlainItem::class]));
        foreach ([BulkSoftItem::class, BulkSoftCachedItem::class] as $class) {
            for ($id = 1; $id <= 3; ++$id) {
                $item = new $class();
                $item->id = $id;
                $item->code = 'code-' . $id;
                $item->setCreatedAt(new DateTimeImmutable('2020-01-01'))->setUpdatedAt(new DateTimeImmutable('2020-01-01'));
                $this->em->persist($item);
            }
        }
        $this->em->flush();
        $this->em->clear();
        $this->observer->events = [];
    }

    protected function tearDown(): void
    {
        $this->pool->failWrites = false;
        $this->logger->onCommit = null;
        foreach ($this->managers as $em) {
            while ($em->getConnection()->isTransactionActive()) {
                $em->getConnection()->rollBack();
            }
        }
        $this->em->getConnection()->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        foreach ($this->managers as $em) {
            $em->getConnection()->close();
        }
    }

    public function testSoftDeleteAndRestoreAreStateRestrictedTypedAtomicUpdates(): void
    {
        $bulk = $this->em->bulk();
        $at = new DateTimeImmutable('2024-02-03 04:05:06');
        $result = $bulk->softDelete(
            BulkSoftItem::class,
            'id IN (:ids) OR id = :last',
            BulkPolicy::CLEAR,
            ['ids' => [1], 'last' => 2],
            ['ids' => ArrayParameterType::INTEGER],
            returning: ['id', 'deletedAt', 'version', 'updatedAt'],
            values: ['deletedAt' => $at]
        );
        self::assertSame(2, $result->affectedRows);
        self::assertTrue($result->databaseCommitted);
        foreach ($result->returnedRows as $row) {
            self::assertSame('2024-02-03 04:05:06', $row['deletedAt']);
            self::assertSame(1, $row['version']);
            self::assertSame('2020-01-01 00:00:00', $row['updatedAt']);
        }
        self::assertSame(0, $bulk->softDelete(BulkSoftItem::class, 'id = 1 OR id = 2', BulkPolicy::CLEAR, values: ['deletedAt' => new DateTimeImmutable('2025-01-01')])->affectedRows);
        self::assertSame($at->format('Y-m-d H:i:s'), $this->em->findIncludingDeleted(BulkSoftItem::class, 1)->getDeletedAt()->format('Y-m-d H:i:s'));
        $restored = $bulk->restore(BulkSoftItem::class, 'TRUE', BulkPolicy::CLEAR, returning: ['id', 'deletedAt'], values: ['updatedAt' => new DateTimeImmutable('2024-03-01')]);
        self::assertSame(2, $restored->affectedRows);
        foreach ($restored->returnedRows as $row) {
            self::assertNull($row['deletedAt']);
        }
        self::assertSame(0, $bulk->restore(BulkSoftItem::class, 'TRUE', BulkPolicy::CLEAR)->affectedRows);
        self::assertSame('2020-01-01', $this->em->find(BulkSoftItem::class, 3)->getUpdatedAt()->format('Y-m-d'));
        self::assertSame(0, BulkSoftItem::$callbacks);
        self::assertSame([BulkOperation::SOFT_DELETE, BulkOperation::SOFT_DELETE, BulkOperation::RESTORE, BulkOperation::RESTORE], array_map(static fn (OperationEvent $e): BulkOperation => $e->bulk->operation, $this->observer->events));
        self::assertSame([2, 0, 2, 0], array_map(static fn (OperationEvent $e): int => $e->bulk->affectedRows, $this->observer->events));
    }

    public function testBypassKeepsIdentityMapAndClearDetachesEvenOnZeroRows(): void
    {
        $item = $this->em->find(BulkSoftItem::class, 1);
        $this->em->bulk()->softDelete(BulkSoftItem::class, 'id = 1', BulkPolicy::BYPASS);
        self::assertTrue($this->em->contains($item));
        self::assertFalse($item->isDeleted());
        self::assertSame($item, $this->em->find(BulkSoftItem::class, 1));
        $this->em->clear();
        self::assertNull($this->em->find(BulkSoftItem::class, 1));
        $item = $this->em->findIncludingDeleted(BulkSoftItem::class, 1);
        $this->em->bulk()->restore(BulkSoftItem::class, 'id = 1', BulkPolicy::BYPASS);
        self::assertTrue($item->isDeleted());
        self::assertNull($this->em->find(BulkSoftItem::class, 1));
        $result = $this->em->bulk()->restore(BulkSoftItem::class, 'id = 1', BulkPolicy::CLEAR);
        self::assertSame(0, $result->affectedRows);
        self::assertFalse($this->em->contains($item));
        self::assertFalse($this->em->find(BulkSoftItem::class, 1)->isDeleted());
    }

    public function testSharedL2InvalidationHoldsForBothPoliciesAndBothStates(): void
    {
        $reader = $this->manager();
        self::assertNotNull($reader->find(BulkSoftCachedItem::class, 1));
        foreach ([BulkPolicy::BYPASS, BulkPolicy::CLEAR] as $policy) {
            self::assertTrue($reader->getCache()->contains(BulkSoftCachedItem::class, 1));
            $this->em->bulk()->softDelete(BulkSoftCachedItem::class, 'id = 1', $policy);
            self::assertFalse($reader->getCache()->contains(BulkSoftCachedItem::class, 1));
            $reader->clear();
            self::assertNull($reader->find(BulkSoftCachedItem::class, 1));
            self::assertCount(1, $reader->createQueryBuilder()->onlyDeleted()->fromEntity(BulkSoftCachedItem::class, 's')->getQuery()->getResult());
            self::assertFalse($reader->getCache()->contains(BulkSoftCachedItem::class, 1));
            $this->em->bulk()->restore(BulkSoftCachedItem::class, 'id = 1', $policy);
            $reader->clear();
            self::assertFalse($reader->find(BulkSoftCachedItem::class, 1)->isDeleted());
        }
        self::assertSame(0, BulkSoftCachedItem::$callbacks);
    }

    public function testUncachedOuterTransactionRollsBackBothChanges(): void
    {
        $db = $this->em->getConnection();
        $db->beginTransaction();
        $result = $this->em->bulk()->softDelete(BulkSoftItem::class, 'TRUE', BulkPolicy::CLEAR);
        self::assertFalse($result->databaseCommitted);
        self::assertSame(0, $this->em->getRepository(BulkSoftItem::class)->count([]));
        $db->rollBack();
        self::assertSame(3, $this->em->getRepository(BulkSoftItem::class)->count([]));
        $this->em->bulk()->softDelete(BulkSoftItem::class, 'TRUE', BulkPolicy::CLEAR);
        $db->beginTransaction();
        self::assertFalse($this->em->bulk()->restore(BulkSoftItem::class, 'TRUE', BulkPolicy::CLEAR)->databaseCommitted);
        $db->rollBack();
        self::assertSame(0, $this->em->getRepository(BulkSoftItem::class)->count([]));
    }

    public static function restoreConflictPolicies(): array
    {
        return [[BulkPolicy::CLEAR], [BulkPolicy::BYPASS]];
    }

    #[DataProvider('restoreConflictPolicies')]
    public function testUniqueConflictRollsBackWholeRestoreWithoutClearing(BulkPolicy $policy): void
    {
        $this->em->bulk()->softDelete(BulkSoftItem::class, 'TRUE', BulkPolicy::CLEAR);
        $replacement = new BulkSoftItem();
        $replacement->id = 4;
        $replacement->code = 'code-1';
        $this->em->persist($replacement);
        $this->em->flush();
        $deleted = $this->em->findIncludingDeleted(BulkSoftItem::class, 2);
        try {
            $this->em->bulk()->restore(BulkSoftItem::class, 'TRUE', $policy);
            self::fail('Expected unique conflict');
        } catch (UniqueConstraintViolationException) {
        }
        self::assertTrue($this->em->contains($deleted));
        self::assertTrue($deleted->isDeleted());
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertSame(3, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->schema . '.item WHERE deleted_at IS NOT NULL'));
        self::assertSame(BulkOperation::RESTORE, $this->observer->events[1]->bulk->operation);
        self::assertTrue($this->observer->events[1]->failed);
        self::assertNull($this->observer->events[1]->bulk->affectedRows);
        self::assertTrue($this->em->isOpen());
        self::assertSame($deleted, $this->em->findIncludingDeleted(BulkSoftItem::class, 2));
        self::assertSame(1, $this->em->getRepository(BulkSoftItem::class)->count([]));

        $replacement->code = 'replacement';
        $this->em->flush();
        $result = $this->em->bulk()->restore(BulkSoftItem::class, 'TRUE', $policy);
        self::assertSame(3, $result->affectedRows);
        self::assertTrue($result->databaseCommitted);
        self::assertSame($policy === BulkPolicy::BYPASS, $this->em->contains($deleted));
        if ($policy === BulkPolicy::BYPASS) {
            self::assertTrue($deleted->isDeleted());
            $this->em->clear();
        }
        self::assertSame(4, $this->em->getRepository(BulkSoftItem::class)->count([]));
        self::assertFalse($this->em->find(BulkSoftItem::class, 2)->isDeleted());
    }

    public static function stateOperations(): array
    {
        return [['softDelete'], ['restore']];
    }

    #[DataProvider('stateOperations')]
    public function testCachedStateChangesRejectOuterTransactionsBeforeWrites(string $operation): void
    {
        $item = $this->em->find(BulkSoftCachedItem::class, 1);
        $db = $this->em->getConnection();
        $db->beginTransaction();
        try {
            $this->em->bulk()->$operation(BulkSoftCachedItem::class, 'TRUE', BulkPolicy::CLEAR);
            self::fail('Expected API-owned transaction requirement');
        } catch (\LogicException $error) {
            self::assertStringContainsString('API-owned', $error->getMessage());
        }
        self::assertTrue($this->em->contains($item));
        self::assertTrue($this->em->getCache()->contains(BulkSoftCachedItem::class, 1));
        self::assertFalse($item->isDeleted());
        $db->rollBack();
    }

    #[DataProvider('stateOperations')]
    public function testCacheFailureBeforeSqlPreservesContextAndDatabase(string $operation): void
    {
        $item = $this->em->find(BulkSoftCachedItem::class, 1);
        $this->pool->failWrites = true;
        try {
            $this->em->bulk()->$operation(BulkSoftCachedItem::class, 'TRUE', BulkPolicy::CLEAR);
            self::fail('Expected cache failure');
        } catch (\ModernORM\Exception\CacheException) {
        }
        $this->pool->failWrites = false;
        self::assertTrue($this->em->contains($item));
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->schema . '.cached WHERE deleted_at IS NOT NULL'));
    }

    #[DataProvider('stateOperations')]
    public function testPostcommitInvalidationFailureReportsCommittedState(string $operation): void
    {
        if ($operation === 'restore') {
            $this->em->bulk()->softDelete(BulkSoftCachedItem::class, 'TRUE', BulkPolicy::CLEAR);
        }
        $this->logger->onCommit = function (): void {
            $this->pool->failWrites = true;
        };
        try {
            $this->em->bulk()->$operation(BulkSoftCachedItem::class, 'TRUE', BulkPolicy::CLEAR);
            self::fail('Expected committed cache failure');
        } catch (BulkOperationException $error) {
            self::assertTrue($error->databaseCommitted);
        }
        $this->pool->failWrites = false;
        self::assertFalse($this->em->isOpen());
        self::assertSame($operation === 'softDelete' ? 3 : 0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->schema . '.cached WHERE deleted_at IS NOT NULL'));
    }

    public function testPoliciesNeverImplicitlyFlushPendingWork(): void
    {
        $item = $this->em->find(BulkSoftItem::class, 1);
        $item->code = 'local-dirty';
        $pending = new BulkSoftItem();
        $pending->id = 9;
        $pending->code = 'pending';
        $this->em->persist($pending);
        $this->em->bulk()->softDelete(BulkSoftItem::class, 'id = 2', BulkPolicy::BYPASS);
        self::assertTrue($this->em->contains($pending));
        self::assertTrue($this->em->contains($item));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->schema . '.item WHERE id = 9'));
        self::assertSame('code-1', $this->em->getConnection()->fetchOne('SELECT code FROM ' . $this->schema . '.item WHERE id = 1'));
        $this->em->bulk()->restore(BulkSoftItem::class, 'id = 2', BulkPolicy::CLEAR);
        self::assertFalse($this->em->contains($pending));
        self::assertFalse($this->em->contains($item));
        $this->em->flush();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->schema . '.item WHERE id = 9'));
    }

    #[DataProvider('stateOperations')]
    public function testPrecommitReaderAndLateFillCannotSurviveStateChange(string $operation): void
    {
        $this->em->find(BulkSoftCachedItem::class, 1);
        $persister = $this->em->getUnitOfWork()->getEntityPersister(BulkSoftCachedItem::class);
        self::assertInstanceOf(\ModernORM\Cache\Persister\Entity\CachedEntityPersister::class, $persister);
        $region = $persister->getCacheRegion();
        $key = new \ModernORM\Cache\EntityCacheKey(BulkSoftCachedItem::class, ['id' => 1]);
        $entry = $region->get($key);
        self::assertNotNull($entry);
        if ($operation === 'restore') {
            $this->em->bulk()->softDelete(BulkSoftCachedItem::class, 'id = 1', BulkPolicy::CLEAR);
        }
        $ticket = null;
        $this->logger->onCommit = function () use ($operation, $region, $key, &$ticket): void {
            $precommit = $this->manager()->find(BulkSoftCachedItem::class, 1);
            self::assertSame($operation === 'restore', $precommit === null);
            $ticket = $region->ticket($key);
        };
        $this->em->bulk()->$operation(BulkSoftCachedItem::class, 'id = 1', BulkPolicy::CLEAR, values: ['code' => 'changed']);
        self::assertNotNull($ticket);
        $region->putAt($ticket, $entry);
        $fresh = $this->manager()->find(BulkSoftCachedItem::class, 1);
        if ($operation === 'softDelete') {
            self::assertNull($fresh);
        } else {
            self::assertNotNull($fresh);
            self::assertSame('changed', $fresh->code);
        }
    }

    public static function invalidCalls(): array
    {
        return [
            'non soft entity' => ['softDelete', BulkSoftPlainItem::class, 'TRUE', [], [], []],
            'empty predicate' => ['softDelete', BulkSoftItem::class, '', [], [], []],
            'restore empty predicate' => ['restore', BulkSoftItem::class, ' ', [], [], []],
            'null timestamp' => ['softDelete', BulkSoftItem::class, 'TRUE', [], [], ['deletedAt' => null]],
            'string timestamp' => ['softDelete', BulkSoftItem::class, 'TRUE', [], [], ['deletedAt' => '2024-01-01']],
            'restore field override' => ['restore', BulkSoftItem::class, 'TRUE', [], [], ['deletedAt' => new DateTimeImmutable()]],
            'restore null override' => ['restore', BulkSoftItem::class, 'TRUE', [], [], ['deletedAt' => null]],
            'unknown value' => ['softDelete', BulkSoftItem::class, 'TRUE', [], [], ['missing' => 1]],
            'reserved parameter' => ['softDelete', BulkSoftItem::class, 'id = :__morm_bulk_0', ['__morm_bulk_0' => 1], [], []],
            'positional parameter' => ['restore', BulkSoftItem::class, 'id = ?', [1], [], []],
        ];
    }

    #[DataProvider('invalidCalls')]
    public function testInvalidArgumentsLeaveManagedStateAndRowsUntouched(string $operation, string $class, string $where, array $parameters, array $types, array $values): void
    {
        $item = $this->em->find(BulkSoftItem::class, 1);
        try {
            $this->em->bulk()->$operation($class, $where, BulkPolicy::CLEAR, $parameters, $types, values: $values);
            self::fail('Expected argument rejection');
        } catch (\InvalidArgumentException) {
        }
        self::assertTrue($this->em->contains($item));
        self::assertSame(3, $this->em->getRepository(BulkSoftItem::class)->count([]));
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }
}
