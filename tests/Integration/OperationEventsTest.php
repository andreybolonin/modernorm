<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use ModernORM\Bulk\BulkPolicy;
use ModernORM\Cache\CacheWritePolicy;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Exception\BulkOperationException;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Query;
use ModernORM\Query\ResultSetMapping;
use ModernORM\Statistics;
use ModernORM\Statistics\BulkOperation;
use ModernORM\Statistics\Middleware;
use ModernORM\Statistics\OperationEvent;
use ModernORM\Statistics\OperationKind;
use ModernORM\Statistics\OperationObserver;
use ModernORM\Statistics\UnitOfWorkPhase;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'record'), ORM\Cacheable(writePolicy: CacheWritePolicy::INVALIDATE)]
final class OperationEventRecord
{
    public function __construct(
        #[ORM\Id, ORM\Column(type: 'integer')] public int $id,
        #[ORM\Column(unique: true)] public string $name,
        #[ORM\Column(type: 'integer')] public int $amount = 0,
    ) {
    }
}

final class RecordingOperationObserver implements OperationObserver
{
    public array $events = [];
    public bool $throw = false;
    public function completed(OperationEvent $event): void
    {
        $this->events[] = $event;
        if ($this->throw) {
            throw new \RuntimeException('Exporter failure');
        }
    }
}

final class OperationEventsTest extends TestCase
{
    private EntityManager $em;
    private Statistics $statistics;
    private RecordingOperationObserver $observer;
    private string $schema;

    protected function setUp(): void
    {
        $this->observer = new RecordingOperationObserver();
        $this->statistics = new Statistics(true, $this->observer);
        $dbal = new DbalConfiguration();
        $dbal->setMiddlewares([new Middleware($this->statistics)]);
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'serverVersion' => '17', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $dbal);
        $this->schema = 'modernorm_events_'.bin2hex(random_bytes(6));
        $connection->executeStatement('CREATE SCHEMA '.$this->schema);
        $connection->executeStatement('CREATE TABLE '.$this->schema.'.record (id INTEGER PRIMARY KEY, name VARCHAR(255) NOT NULL UNIQUE, amount INTEGER NOT NULL DEFAULT 0)');
        $this->em = new EntityManager($connection, $this->config());
        $this->map($this->em);
        $this->reset();
    }

    private function config(?ArrayAdapter $pool = null): Configuration
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        if ($pool !== null) {
            $config->setSecondLevelCache($pool);
        }
        return $config;
    }

    private function map(EntityManager $em): void
    {
        $em->getClassMetadata(OperationEventRecord::class)->setPrimaryTable(['name' => 'record', 'schema' => $this->schema]);
    }

    protected function tearDown(): void
    {
        $db = $this->em->getConnection();
        while ($db->isTransactionActive()) {
            $db->rollBack();
        }
        $db->executeStatement('DROP SCHEMA '.$this->schema.' CASCADE');
        $db->close();
    }

    private function reset(): void
    {
        $this->statistics->reset();
        $this->observer->events = [];
    }

    private function events(OperationKind $kind): array
    {
        return array_values(array_filter($this->observer->events, static fn (OperationEvent $event): bool => $event->kind === $kind));
    }

    private function seed(): void
    {
        $this->em->getConnection()->executeStatement("INSERT INTO $this->schema.record VALUES (1, 'secret-one', 1), (2, 'secret-two', 2), (3, 'secret-three', 3)");
        $this->reset();
    }

    private function mapping(): ResultSetMapping
    {
        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(OperationEventRecord::class, 'r');
        foreach (['id', 'name', 'amount'] as $field) {
            $rsm->addFieldResult('r', $field, $field);
        }
        return $rsm;
    }

    public function testAllBulkOperationsExposeCountsAndPoliciesWithoutQueryData(): void
    {
        $bulk = $this->em->bulk();
        $bulk->insert(OperationEventRecord::class, [['id' => 1, 'name' => 'secret-one'], ['id' => 2, 'name' => 'secret-two']], BulkPolicy::BYPASS, returning: ['id']);
        $bulk->upsert(OperationEventRecord::class, [['id' => 1, 'name' => 'secret-updated']], ['id'], ['name'], BulkPolicy::BYPASS);
        $bulk->update(OperationEventRecord::class, ['amount' => 7], 'id = :id', BulkPolicy::CLEAR, ['id' => 1], returning: ['name']);
        $bulk->delete(OperationEventRecord::class, 'id = :id', BulkPolicy::BYPASS, ['id' => 2]);
        $events = $this->events(OperationKind::BULK);
        self::assertSame(BulkOperation::cases(), array_map(static fn (OperationEvent $event): BulkOperation => $event->bulk->operation, $events));
        self::assertSame([2, 1, 1, 1], array_map(static fn (OperationEvent $event): int => $event->bulk->affectedRows, $events));
        self::assertSame(BulkPolicy::CLEAR, $events[2]->bulk->policy);
        foreach ($events as $event) {
            self::assertTrue($event->bulk->databaseCommitted);
            self::assertFalse($event->failed);
            self::assertGreaterThan(0, $event->durationNanoseconds);
            self::assertStringNotContainsString('secret-', json_encode($event));
            self::assertStringNotContainsString('WHERE', json_encode($event));
        }
        $snapshot = $this->statistics->snapshot()->bulk;
        self::assertSame(4, $snapshot->count);
        self::assertSame(3, $snapshot->inputRows);
        self::assertSame(5, $snapshot->affectedRows);
        self::assertSame(3, $snapshot->returnedRows);
        self::assertSame(0, $snapshot->errors);
    }

    public function testBulkFailuresPreserveSqlExceptionAndUnknownAffectedCount(): void
    {
        $this->seed();
        try {
            $this->em->bulk()->insert(OperationEventRecord::class, [['id' => 4, 'name' => 'secret-one']], BulkPolicy::BYPASS);
            self::fail('Expected unique failure');
        } catch (UniqueConstraintViolationException) {
        }
        $event = $this->events(OperationKind::BULK)[0];
        self::assertTrue($event->failed);
        self::assertNull($event->bulk->affectedRows);
        self::assertNull($event->bulk->databaseCommitted);
        self::assertSame(1, $this->statistics->snapshot()->bulk->errors);
        $this->reset();
        try {
            $this->em->bulk()->insert(OperationEventRecord::class, [], BulkPolicy::CLEAR);
            self::fail('Expected validation failure');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame(0, $this->statistics->snapshot()->sqlCount);
        self::assertTrue($this->events(OperationKind::BULK)[0]->failed);
    }

    public function testBulkInCallerTransactionDoesNotClaimCommit(): void
    {
        $db = $this->em->getConnection();
        $db->beginTransaction();
        $this->em->bulk()->insert(OperationEventRecord::class, [['id' => 1, 'name' => 'pending']], BulkPolicy::BYPASS);
        self::assertFalse($this->events(OperationKind::BULK)[0]->bulk->databaseCommitted);
        $db->rollBack();
        self::assertSame(1, $this->statistics->snapshot()->bulk->affectedRows);
        self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM '.$this->schema.'.record'));
    }

    public function testPostCommitBulkFailureReportsCommittedOutcome(): void
    {
        $pool = new class () extends ArrayAdapter {
            private int $saves = 0;
            public function save(CacheItemInterface $item): bool
            {
                return ++$this->saves !== 2 && parent::save($item);
            }
        };
        $em = new EntityManager($this->em->getConnection(), $this->config($pool));
        $this->map($em);
        try {
            $em->bulk()->insert(OperationEventRecord::class, [['id' => 1, 'name' => 'committed']], BulkPolicy::BYPASS);
            self::fail('Expected cache failure');
        } catch (BulkOperationException $error) {
            self::assertTrue($error->databaseCommitted);
        }
        $event = $this->events(OperationKind::BULK)[0];
        self::assertTrue($event->failed);
        self::assertTrue($event->bulk->databaseCommitted);
        self::assertNull($event->bulk->affectedRows);
        self::assertSame('committed', $this->em->getConnection()->fetchOne('SELECT name FROM '.$this->schema.'.record'));
    }

    public function testUnitOfWorkPhasesDescribeScheduledWorkAndEmptyFlush(): void
    {
        $this->em->flush();
        self::assertSame(1, $this->statistics->snapshot()->unitOfWork->phase(UnitOfWorkPhase::CHANGE_SETS)->count);
        self::assertSame(0, $this->statistics->snapshot()->unitOfWork->phase(UnitOfWorkPhase::COMMIT)->count);
        $this->reset();
        $record = new OperationEventRecord(1, 'first');
        $this->em->persist($record);
        $this->em->flush();
        $record->name = 'updated';
        $this->em->flush();
        $this->em->remove($record);
        $this->em->flush();
        $writes = array_values(array_filter($this->events(OperationKind::UNIT_OF_WORK), static fn (OperationEvent $e): bool => $e->unitOfWork->phase === UnitOfWorkPhase::WRITES));
        self::assertSame(1, $writes[0]->unitOfWork->insertions);
        self::assertSame(1, $writes[1]->unitOfWork->updates);
        self::assertSame(1, $writes[2]->unitOfWork->deletions);
        foreach (UnitOfWorkPhase::cases() as $phase) {
            self::assertSame(3, $this->statistics->snapshot()->unitOfWork->phase($phase)->count);
            self::assertSame(0, $this->statistics->snapshot()->unitOfWork->phase($phase)->errors);
        }
    }

    public function testWriteFailureDoesNotReportAnAttemptedCommit(): void
    {
        $this->em->persist(new OperationEventRecord(1, 'duplicate'));
        $this->em->persist(new OperationEventRecord(2, 'duplicate'));
        try {
            $this->em->flush();
            self::fail('Expected unique failure');
        } catch (UniqueConstraintViolationException) {
        }
        $work = $this->statistics->snapshot()->unitOfWork;
        self::assertSame(1, $work->phase(UnitOfWorkPhase::WRITES)->errors);
        self::assertSame(0, $work->phase(UnitOfWorkPhase::COMMIT)->count);
        self::assertSame(1, $this->statistics->snapshot()->flushErrors);
    }

    public function testHydrationCountsRowsSeparatelyFromNewEntityInstances(): void
    {
        $this->seed();
        $this->em->getRepository(OperationEventRecord::class)->findAll();
        $this->em->getRepository(OperationEventRecord::class)->findAll();
        $snapshot = $this->statistics->snapshot()->hydration;
        self::assertSame(2, $snapshot->count);
        self::assertSame(6, $snapshot->rows);
        self::assertSame(3, $snapshot->entitiesCreated);
        self::assertSame([3, 0], array_map(static fn (OperationEvent $e): int => $e->hydration->entitiesCreated, $this->events(OperationKind::HYDRATION)));
    }

    public function testScalarAndArrayHydrationDoNotCreateEntities(): void
    {
        $this->seed();
        foreach ([Query::HYDRATE_SCALAR, Query::HYDRATE_SCALAR_COLUMN, Query::HYDRATE_SINGLE_SCALAR] as $mode) {
            $rsm = new ResultSetMapping();
            $rsm->addScalarResult('value', 'value', 'integer');
            $this->em->createNativeQuery('SELECT 1 AS value', $rsm)->getResult($mode);
        }
        $this->em->createNativeQuery('SELECT * FROM '.$this->schema.'.record ORDER BY id', $this->mapping())->getResult(Query::HYDRATE_ARRAY);
        self::assertSame(6, $this->statistics->snapshot()->hydration->rows);
        self::assertSame(0, $this->statistics->snapshot()->hydration->entitiesCreated);
    }

    public function testStreamingExcludesConsumerTimeAndAttributesNestedLoadsSeparately(): void
    {
        $this->seed();
        $stream = $this->em->createNativeQuery('SELECT * FROM '.$this->schema.'.record ORDER BY id', $this->mapping())->toIterable();
        $stream->rewind();
        self::assertSame(1, $stream->current()->id);
        usleep(30000);
        $this->em->find(OperationEventRecord::class, 2);
        unset($stream);
        $events = $this->events(OperationKind::HYDRATION);
        $streamEvent = array_values(array_filter($events, static fn (OperationEvent $e): bool => $e->hydration->streamed))[0];
        self::assertFalse($streamEvent->failed);
        self::assertFalse($streamEvent->hydration->completed);
        self::assertSame(1, $streamEvent->hydration->rows);
        self::assertSame(1, $streamEvent->hydration->entitiesCreated);
        self::assertGreaterThan(20_000_000, $streamEvent->endedAtUnixNanoseconds() - $streamEvent->startedAtUnixNanoseconds - $streamEvent->durationNanoseconds);
        self::assertSame(2, $this->statistics->snapshot()->hydration->entitiesCreated);
        self::assertSame(1, $this->statistics->snapshot()->hydration->incomplete);
    }

    public function testFullStreamAndFailedHydrationHaveDifferentOutcomes(): void
    {
        $this->seed();
        $stream = $this->em->createNativeQuery('SELECT * FROM '.$this->schema.'.record ORDER BY id', $this->mapping())->toIterable();
        iterator_to_array($stream);
        self::assertTrue($this->events(OperationKind::HYDRATION)[0]->hydration->completed);
        self::assertSame(3, $this->statistics->snapshot()->hydration->rows);
        $this->em->clear();
        $this->reset();
        $rsm = new ResultSetMapping();
        $rsm->addScalarResult('value', 'value', 'datetime_immutable');
        try {
            $this->em->createNativeQuery("SELECT 'invalid-date-value' AS value", $rsm)->getResult(Query::HYDRATE_ARRAY);
            self::fail('Expected hydration conversion error');
        } catch (\Doctrine\DBAL\Types\Exception\InvalidFormat) {
        }
        self::assertSame(1, $this->statistics->snapshot()->hydration->errors);
        self::assertSame(0, $this->statistics->snapshot()->sqlErrors);
    }

    public function testPrepareFailureRemovesHydratorListenerAndRecordsAnError(): void
    {
        $hydrator = $this->em->newHydrator(Query::HYDRATE_SIMPLEOBJECT);
        $statement = $this->em->getConnection()->executeQuery('SELECT 1');
        try {
            $hydrator->hydrateAll($statement, new ResultSetMapping());
            self::fail('Expected prepare failure for an empty entity mapping');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('ResultSetMapping', $error->getMessage());
        }
        self::assertSame(1, $this->statistics->snapshot()->hydration->errors);
        self::assertSame(0, $this->statistics->snapshot()->hydration->rows);
        self::assertNotContains($hydrator, $this->em->getEventManager()->getListeners(\ModernORM\Events::onClear));
    }

    public function testCachedHydrationAndExporterFailureRemainObservableAndSafe(): void
    {
        $this->seed();
        $em = new EntityManager($this->em->getConnection(), $this->config(new ArrayAdapter()));
        $this->map($em);
        $em->find(OperationEventRecord::class, 1);
        $em->clear();
        $this->reset();
        $this->observer->throw = true;
        self::assertSame('secret-one', $em->find(OperationEventRecord::class, 1)->name);
        self::assertSame(0, $this->statistics->snapshot()->sqlCount);
        self::assertSame(1, $this->statistics->snapshot()->hydration->rows);
        self::assertSame(1, $this->statistics->snapshot()->observerErrors);
    }
}
