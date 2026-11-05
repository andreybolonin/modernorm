<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;
use ModernORM\Statistics\StatisticsConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StatisticsTest extends TestCase
{
    public function testDisabledByDefaultAndUninstrumentedManagerHasNoCollector(): void
    {
        $statistics = new Statistics();
        self::assertFalse($statistics->isEnabled());
        $statistics->reset();
        self::assertFalse($statistics->isEnabled());
        self::assertSame(0, $statistics->snapshot()->sqlCount);
        self::assertSame(0, $statistics->snapshot()->flushCount);
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '17']), $config);
        self::assertNull($em->getStatistics());
        self::assertFalse($em->getConnection()->isConnected());
    }

    public function testSnapshotIsImmutableAndIndependentOfReset(): void
    {
        $statistics = new Statistics(enabled: true);
        $wrapped = $this->createMock(DriverConnection::class);
        $wrapped->method('query')->willReturn($this->createMock(Result::class));
        (new StatisticsConnection($wrapped, $statistics))->query('SELECT 1');
        $snapshot = $statistics->snapshot();
        $statistics->reset();
        self::assertSame(1, $snapshot->sqlCount);
        self::assertSame(0, $statistics->snapshot()->sqlCount);
        $this->expectException(\Error::class);
        $snapshot->sqlCount = 99;
    }

    public function testResetDuringExecutionDoesNotLeakIntoNextWindow(): void
    {
        $statistics = new Statistics(enabled: true);
        $result = $this->createMock(Result::class);
        $wrapped = $this->createMock(DriverConnection::class);
        $wrapped->method('query')->willReturnCallback(static function (string $sql) use ($statistics, $result): Result {
            if ($sql === 'reset') {
                $statistics->reset();
            }
            return $result;
        });
        $connection = new StatisticsConnection($wrapped, $statistics);
        self::assertSame($result, $connection->query('reset'));
        self::assertSame(0, $statistics->snapshot()->sqlCount);
        self::assertSame(0, $statistics->snapshot()->sqlTimeNanoseconds);
        $connection->query('next window');
        self::assertSame(1, $statistics->snapshot()->sqlCount);
    }

    public function testDisableAndReenableDuringExecutionDiscardsOldMeasurement(): void
    {
        $statistics = new Statistics(enabled: true);
        $result = $this->createMock(Result::class);
        $wrapped = $this->createMock(DriverConnection::class);
        $wrapped->method('query')->willReturnCallback(static function (string $sql) use ($statistics, $result): Result {
            if ($sql === 'toggle') {
                $statistics->disable();
                $statistics->enable();
            }
            return $result;
        });
        $connection = new StatisticsConnection($wrapped, $statistics);
        $connection->query('toggle');
        self::assertSame(0, $statistics->snapshot()->sqlCount);
        self::assertSame(0, $statistics->snapshot()->sqlTimeNanoseconds);
        $connection->query('enabled');
        self::assertSame(1, $statistics->snapshot()->sqlCount);
        $statistics->disable();
        $connection->query('disabled');
        self::assertSame(1, $statistics->snapshot()->sqlCount);
    }

    public function testFailedPreparationRecordsOneAttemptAndPreservesException(): void
    {
        $statistics = new Statistics(enabled: true);
        $exception = new RuntimeException('prepare failed');
        $wrapped = $this->createMock(DriverConnection::class);
        $wrapped->method('prepare')->willThrowException($exception);
        try {
            (new StatisticsConnection($wrapped, $statistics))->prepare('invalid statement');
            self::fail('Expected original exception');
        } catch (RuntimeException $actual) {
            self::assertSame($exception, $actual);
        }
        self::assertSame(1, $statistics->snapshot()->sqlCount);
        self::assertSame(1, $statistics->snapshot()->sqlErrors);
    }

    public function testSameCollectorCannotWrapTwoDrivers(): void
    {
        $statistics = new Statistics(enabled: true);
        $middleware = new Middleware($statistics);
        $middleware->wrap($this->createMock(Driver::class));
        $this->expectException(\LogicException::class);
        $middleware->wrap($this->createMock(Driver::class));
    }

    public function testMiddlewareAddedAfterConnectionCreationIsRejected(): void
    {
        $dbal = new DBALConfiguration();
        $connection = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '17'], $dbal);
        $dbal->setMiddlewares([new Middleware(new Statistics(enabled: true))]);
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $this->expectException(\LogicException::class);
        new EntityManager($connection, $config);
    }
}
