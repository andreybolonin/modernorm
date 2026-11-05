<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Event\PreFlushEventArgs;
use ModernORM\Events;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_statistics')]
final class StatisticsRecord
{
    public function __construct(
        #[ORM\Id, ORM\Column] public int $id,
        #[ORM\Column(unique: true)] public string $name,
    ) {
    }
}

final class StatisticsTest extends TestCase
{
    private Statistics $statistics;
    private EntityManager $em;

    private function parameters(): array
    {
        return [
            'driver' => 'pdo_pgsql', 'serverVersion' => '17',
            'host' => getenv('PGHOST') ?: '127.0.0.1', 'port' => getenv('PGPORT') ?: 5432,
            'user' => getenv('PGUSER') ?: 'postgres', 'password' => getenv('PGPASSWORD') ?: 'postgres',
            'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ];
    }

    private function configuration(): Configuration
    {
        $configuration = new Configuration();
        $configuration->setMetadataDriverImpl(new AttributeDriver([]));
        return $configuration;
    }

    protected function setUp(): void
    {
        $this->statistics = new Statistics(enabled: true);
        $dbal = new DBALConfiguration();
        // Coexistence with another middleware must not count prepared execution twice.
        $dbal->setMiddlewares([new LoggingMiddleware(new NullLogger()), new Middleware($this->statistics)]);
        $connection = DriverManager::getConnection($this->parameters(), $dbal);
        $this->em = new EntityManager($connection, $this->configuration());
        $connection->executeStatement('DROP TABLE IF EXISTS modernorm_test_statistics');
        $connection->executeStatement('CREATE TABLE modernorm_test_statistics (id INT PRIMARY KEY, name VARCHAR(255) NOT NULL UNIQUE)');
        $this->em->getClassMetadata(StatisticsRecord::class);
        $this->statistics->reset();
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $connection->executeStatement('DROP TABLE IF EXISTS modernorm_test_statistics');
        $connection->close();
    }

    public function testQueryExecAndRepeatedPreparedExecutionCountOnceEach(): void
    {
        $connection = $this->em->getConnection();
        self::assertSame($this->statistics, $this->em->getStatistics());
        $connection->executeQuery('SELECT 1')->fetchOne();
        $connection->executeStatement("INSERT INTO modernorm_test_statistics VALUES (1, 'original')");
        $statement = $connection->prepare('SELECT name FROM modernorm_test_statistics WHERE id = ?');
        self::assertSame(2, $this->statistics->snapshot()->sqlCount);
        $statement->bindValue(1, 1);
        self::assertSame('original', $statement->executeQuery()->fetchOne());
        self::assertSame('original', $statement->executeQuery()->fetchOne());
        $snapshot = $this->statistics->snapshot();
        self::assertSame(4, $snapshot->sqlCount);
        self::assertSame(0, $snapshot->sqlErrors);
        self::assertSame(0, $snapshot->flushCount);
        self::assertGreaterThan(0, $snapshot->sqlTimeNanoseconds);
    }

    public function testSqlTimeIncludesDriverExecutionAndSnapshotContainsNoQueryData(): void
    {
        $this->em->getConnection()->executeQuery('SELECT pg_sleep(0.01)');
        $snapshot = $this->statistics->snapshot();
        self::assertSame(1, $snapshot->sqlCount);
        self::assertGreaterThanOrEqual(5_000_000, $snapshot->sqlTimeNanoseconds);
        self::assertSame($snapshot->sqlTimeNanoseconds / 1_000_000, $snapshot->sqlTimeMilliseconds());
        self::assertSame(0.0, $snapshot->flushTimeMilliseconds());
        self::assertSame([
            'sqlCount', 'sqlTimeNanoseconds', 'sqlErrors', 'flushCount', 'flushTimeNanoseconds', 'flushErrors', 'batch', 'observerErrors',
        ], array_keys(get_object_vars($snapshot)));
    }

    public function testDirectSqlErrorIsCountedAndDoesNotCloseManager(): void
    {
        try {
            $this->em->getConnection()->executeQuery('SELECT missing_column FROM modernorm_test_statistics');
            self::fail('Expected invalid SQL');
        } catch (InvalidFieldNameException) {
        }
        $snapshot = $this->statistics->snapshot();
        self::assertSame(1, $snapshot->sqlCount);
        self::assertSame(1, $snapshot->sqlErrors);
        self::assertSame(0, $snapshot->flushErrors);
        self::assertTrue($this->em->isOpen());
        self::assertSame(1, $this->em->getConnection()->fetchOne('SELECT 1'));
        self::assertSame(2, $this->statistics->snapshot()->sqlCount);
        self::assertSame(1, $snapshot->sqlCount);
    }

    public function testPreparedFailureAddsOneErrorWithoutCountingPrepareOrBind(): void
    {
        $statement = $this->em->getConnection()->prepare('INSERT INTO modernorm_test_statistics VALUES (?, ?)');
        $statement->bindValue(1, 1);
        $statement->bindValue(2, 'duplicate');
        self::assertSame(0, $this->statistics->snapshot()->sqlCount);
        $statement->executeStatement();
        $statement->bindValue(1, 2);
        try {
            $statement->executeStatement();
            self::fail('Expected UNIQUE failure');
        } catch (UniqueConstraintViolationException) {
        }
        self::assertSame(2, $this->statistics->snapshot()->sqlCount);
        self::assertSame(1, $this->statistics->snapshot()->sqlErrors);
        self::assertTrue($this->em->isOpen());
    }

    public function testFlushCountsIncludeEmptyFlushAndDoNotDoubleCountSql(): void
    {
        $record = new StatisticsRecord(1, 'original');
        $this->em->persist($record);
        $this->em->flush();
        $this->em->flush();
        $record->name = 'updated';
        $this->em->flush();
        $snapshot = $this->statistics->snapshot();
        self::assertSame(2, $snapshot->sqlCount);
        self::assertSame(3, $snapshot->flushCount);
        self::assertSame(0, $snapshot->sqlErrors);
        self::assertSame(0, $snapshot->flushErrors);
        self::assertGreaterThan(0, $snapshot->flushTimeNanoseconds);
        self::assertGreaterThanOrEqual($snapshot->sqlTimeNanoseconds, $snapshot->flushTimeNanoseconds);
    }

    public function testFailedFlushCountsAttemptsEvenThoughDatabaseRollsBack(): void
    {
        $this->em->persist(new StatisticsRecord(1, 'duplicate'));
        $this->em->persist(new StatisticsRecord(2, 'duplicate'));
        try {
            $this->em->flush();
            self::fail('Expected UNIQUE failure');
        } catch (UniqueConstraintViolationException) {
        }
        $snapshot = $this->statistics->snapshot();
        self::assertSame(2, $snapshot->sqlCount);
        self::assertSame(1, $snapshot->sqlErrors);
        self::assertSame(1, $snapshot->flushCount);
        self::assertSame(1, $snapshot->flushErrors);
        self::assertFalse($this->em->isOpen());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_statistics'));
        self::assertSame(2, $snapshot->sqlCount);
    }

    public function testOuterRollbackKeepsStatisticsAndSavepointSqlIsVisible(): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $this->em->persist(new StatisticsRecord(1, 'rollback'));
        $this->em->flush();
        $beforeRollback = $this->statistics->snapshot();
        self::assertSame(3, $beforeRollback->sqlCount); // SAVEPOINT, INSERT, RELEASE SAVEPOINT.
        self::assertSame(1, $beforeRollback->flushCount);
        self::assertSame(0, $beforeRollback->flushErrors);
        $connection->rollBack();
        self::assertEquals($beforeRollback, $this->statistics->snapshot());
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM modernorm_test_statistics'));
    }

    public function testPreFlushFailureIsAFlushErrorWithoutSqlOrManagerClosure(): void
    {
        $this->em->getEventManager()->addEventListener([Events::preFlush], new class () {
            public function preFlush(PreFlushEventArgs $args): void
            {
                throw new RuntimeException('preFlush failed');
            }
        });
        try {
            $this->em->flush();
            self::fail('Expected listener exception');
        } catch (RuntimeException $exception) {
            self::assertSame('preFlush failed', $exception->getMessage());
        }
        $snapshot = $this->statistics->snapshot();
        self::assertSame(0, $snapshot->sqlCount);
        self::assertSame(1, $snapshot->flushCount);
        self::assertSame(1, $snapshot->flushErrors);
        self::assertTrue($this->em->isOpen());
    }

    public function testPostFlushFailureCountsAsErrorAfterCommit(): void
    {
        $this->em->getEventManager()->addEventListener([Events::postFlush], new class () {
            public function postFlush(): void
            {
                throw new RuntimeException('postFlush failed');
            }
        });
        $this->em->persist(new StatisticsRecord(1, 'committed'));
        try {
            $this->em->flush();
            self::fail('Expected listener exception');
        } catch (RuntimeException $exception) {
            self::assertSame('postFlush failed', $exception->getMessage());
        }
        $snapshot = $this->statistics->snapshot();
        self::assertSame(1, $snapshot->sqlCount);
        self::assertSame(0, $snapshot->sqlErrors);
        self::assertSame(1, $snapshot->flushCount);
        self::assertSame(1, $snapshot->flushErrors);
        self::assertTrue($this->em->isOpen());
        self::assertSame('committed', $this->em->getConnection()->fetchOne('SELECT name FROM modernorm_test_statistics WHERE id = 1'));
    }

    public function testDisableFreezesCountersAndPreparedStatementsCanResumeMeasurement(): void
    {
        $this->statistics->disable();
        $connection = $this->em->getConnection();
        $statement = $connection->prepare('SELECT name FROM modernorm_test_statistics WHERE id = ?');
        $this->em->persist(new StatisticsRecord(1, 'disabled'));
        $this->em->flush();
        self::assertSame(0, $this->statistics->snapshot()->sqlCount);
        self::assertSame(0, $this->statistics->snapshot()->flushCount);
        self::assertSame(0, $this->statistics->snapshot()->sqlTimeNanoseconds);
        self::assertSame(0, $this->statistics->snapshot()->flushTimeNanoseconds);
        $this->statistics->enable();
        $statement->bindValue(1, 1);
        self::assertSame('disabled', $statement->executeQuery()->fetchOne());
        $snapshot = $this->statistics->snapshot();
        self::assertSame(1, $snapshot->sqlCount);
        $this->statistics->disable();
        $connection->fetchOne('SELECT 1');
        $this->em->flush();
        self::assertEquals($snapshot, $this->statistics->snapshot());
    }

    public function testSharedManagersAndDirectSqlUseConnectionScopeAndResetDoesNotClearEntities(): void
    {
        $record = new StatisticsRecord(1, 'original');
        $this->em->persist($record);
        $this->em->flush();
        $snapshot = $this->statistics->snapshot();
        $second = new EntityManager($this->em->getConnection(), $this->configuration());
        self::assertSame($this->statistics, $second->getStatistics());
        $second->flush();
        self::assertSame(2, $this->statistics->snapshot()->flushCount);
        $second->clear();
        self::assertSame(2, $this->statistics->snapshot()->flushCount);
        $this->statistics->reset();
        self::assertTrue($this->statistics->isEnabled());
        self::assertTrue($this->em->contains($record));
        self::assertSame(0, $this->statistics->snapshot()->sqlCount);
        self::assertSame(0, $this->statistics->snapshot()->flushCount);
        self::assertSame(1, $snapshot->sqlCount);
        self::assertSame(1, $snapshot->flushCount);
    }

    public function testSeparateConnectionsAreIsolatedAndReconnectKeepsScope(): void
    {
        $otherStatistics = new Statistics(enabled: true);
        $dbal = new DBALConfiguration();
        $dbal->setMiddlewares([new Middleware($otherStatistics)]);
        $other = DriverManager::getConnection($this->parameters(), $dbal);
        try {
            $other->fetchOne('SELECT 1');
            self::assertSame(1, $otherStatistics->snapshot()->sqlCount);
            self::assertSame(0, $this->statistics->snapshot()->sqlCount);
            $other->close();
            $other->fetchOne('SELECT 1');
            self::assertSame(2, $otherStatistics->snapshot()->sqlCount);
            self::assertSame(0, $this->statistics->snapshot()->sqlCount);
        } finally {
            $other->close();
        }
    }
    public function testResetInsidePostFlushDiscardsPreviousFlushMeasurement(): void
    {
        $this->em->getEventManager()->addEventListener([Events::postFlush], new class ($this->statistics) {
            private bool $reset = false;
            public function __construct(private Statistics $statistics)
            {
            }
            public function postFlush(): void
            {
                if (! $this->reset) {
                    $this->statistics->reset();
                    $this->reset = true;
                }
            }
        });
        $record = new StatisticsRecord(1, 'reset-window');
        $this->em->persist($record);
        $this->em->flush();
        self::assertTrue($this->em->contains($record));
        self::assertSame(0, $this->statistics->snapshot()->flushCount);
        self::assertSame(0, $this->statistics->snapshot()->sqlCount);
        self::assertSame(0, $this->statistics->snapshot()->flushTimeNanoseconds);
        $this->em->flush();
        self::assertSame(1, $this->statistics->snapshot()->flushCount);
        self::assertSame(0, $this->statistics->snapshot()->sqlCount);
    }

    public function testFlushOnClosedManagerIsCountedAsFailedAttemptWithoutSql(): void
    {
        $this->em->close();
        try {
            $this->em->flush();
            self::fail('Expected closed-manager exception');
        } catch (\ModernORM\Exception\EntityManagerClosed) {
        }
        self::assertSame(1, $this->statistics->snapshot()->flushCount);
        self::assertSame(1, $this->statistics->snapshot()->flushErrors);
        self::assertSame(0, $this->statistics->snapshot()->sqlCount);
    }

    public function testDisabledErrorsPropagateWithoutChangingCounters(): void
    {
        $this->statistics->disable();
        $this->em->persist(new StatisticsRecord(1, 'duplicate'));
        $this->em->persist(new StatisticsRecord(2, 'duplicate'));
        try {
            $this->em->flush();
            self::fail('Expected UNIQUE failure');
        } catch (UniqueConstraintViolationException) {
        }
        self::assertFalse($this->em->isOpen());
        $snapshot = $this->statistics->snapshot();
        self::assertSame(0, $snapshot->sqlCount);
        self::assertSame(0, $snapshot->sqlErrors);
        self::assertSame(0, $snapshot->flushCount);
        self::assertSame(0, $snapshot->flushErrors);
        self::assertSame(0, $snapshot->sqlTimeNanoseconds);
        self::assertSame(0, $snapshot->flushTimeNanoseconds);
    }

    public function testLoggingMiddlewareOutsideStatisticsStillCountsEachExecutionOnce(): void
    {
        $statistics = new Statistics(enabled: true);
        $dbal = new DBALConfiguration();
        $dbal->setMiddlewares([new Middleware($statistics), new LoggingMiddleware(new NullLogger())]);
        $connection = DriverManager::getConnection($this->parameters(), $dbal);
        try {
            $connection->fetchOne('SELECT 1');
            $connection->executeQuery('SELECT ?', [2])->fetchOne();
            self::assertSame(2, $statistics->snapshot()->sqlCount);
            self::assertSame(0, $statistics->snapshot()->sqlErrors);
            self::assertSame(0, $this->statistics->snapshot()->sqlCount);
        } finally {
            $connection->close();
        }
    }

}
