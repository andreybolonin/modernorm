<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use ModernORM\Statistics;
use ModernORM\Statistics\DmlOperation;
use ModernORM\Statistics\OperationEvent;
use ModernORM\Statistics\OperationObserver;
use ModernORM\Statistics\StatisticsConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DmlStatisticsTest extends TestCase
{
    public static function statements(): array
    {
        return [
            ['INSERT INTO t VALUES (1)', 'insert'], ['update t set x = 1', 'update'], ['DELETE FROM t;', 'delete'],
            [" -- hello\n /* outer /* nested */ done */ UPDATE t SET x = 0; -- done", 'update'],
            ["INSERT INTO t VALUES ('semi;colon', 'it''s fine')", 'insert'],
            ['UPDATE "semi;colon" SET x = $$hello;world$$;', 'update'],
            ['INSERT INTO t VALUES ($tag$semi;colon$tag$)', 'insert'],
            ["INSERT INTO t VALUES (E'escaped\\';quote')", 'insert'],
            ["INSERT INTO t VALUES ('backslash\\'); DELETE FROM t", null],
            ['INSERT INTO t VALUES (1); DELETE FROM t', null], ['UPDATE t SET x = 1;;', null],
            ['WITH changed AS (DELETE FROM t RETURNING *) SELECT * FROM changed', null],
            ['SELECT \'INSERT\'', null], ['EXPLAIN UPDATE t SET x = 1', null], ['COPY t FROM STDIN', null],
            ['/* only comment */', null], ['INSERTION t', null],
        ];
    }

    #[DataProvider('statements')]
    public function testClassifiesOnlySingleDirectDml(string $sql, ?string $expected): void
    {
        self::assertSame($expected, DmlOperation::fromSql($sql)?->value);
    }

    public function testPreparedBeforeEnableClassifiesEveryExecutionWithoutCountingPrepare(): void
    {
        $statistics = new Statistics();
        $result = $this->createMock(Result::class);
        $result->method('rowCount')->willReturn(2);
        $statement = $this->createMock(Statement::class);
        $statement->method('execute')->willReturn($result);
        $connection = $this->createMock(Connection::class);
        $connection->method('prepare')->willReturn($statement);
        $prepared = (new StatisticsConnection($connection, $statistics))->prepare('INSERT INTO t VALUES (?)');
        self::assertSame(0, $statistics->snapshot()->sqlCount);
        $statistics->enable();
        $prepared->execute();
        $prepared->execute();
        self::assertSame(2, $statistics->snapshot()->dml->insert->count);
        self::assertSame(4, $statistics->snapshot()->dml->insert->affectedRows);
        $old = $statistics->snapshot();
        $statistics->reset();
        self::assertSame(0, $statistics->snapshot()->dml->insert->count);
        self::assertSame(2, $old->dml->insert->count);
    }

    public function testRowCountFailureDoesNotTurnSuccessIntoFailure(): void
    {
        $statistics = new Statistics(true);
        $result = $this->createMock(Result::class);
        $result->method('rowCount')->willThrowException(new \RuntimeException('row count unavailable'));
        $connection = $this->createMock(Connection::class);
        $connection->method('query')->willReturn($result);
        self::assertSame($result, (new StatisticsConnection($connection, $statistics))->query('UPDATE t SET x = 1'));
        $update = $statistics->snapshot()->dml->update;
        self::assertSame(1, $update->count);
        self::assertSame(0, $update->errors);
        self::assertSame(1, $update->unknownRowCounts);
        self::assertSame(0, $update->affectedRows);
    }

    public function testFailureIsAttemptWithUnknownRowsAndExecStringCountIsSupported(): void
    {
        $events = new class () implements OperationObserver {
            public array $events = [];
            public function completed(OperationEvent $event): void
            {
                $this->events[] = $event;
            }
        };
        $statistics = new Statistics(true, $events);
        $connection = $this->createMock(Connection::class);
        $connection->method('query')->willThrowException(new \RuntimeException('SQL failed'));
        $connection->method('exec')->willReturn('3');
        $wrapped = new StatisticsConnection($connection, $statistics);
        try {
            $wrapped->query('DELETE FROM t');
            self::fail();
        } catch (\RuntimeException) {
        }
        self::assertSame(1, $statistics->snapshot()->dml->delete->errors);
        self::assertNull($events->events[0]->dml->affectedRows);
        self::assertSame('3', $wrapped->exec('INSERT INTO t VALUES (1),(2),(3)'));
        self::assertSame(3, $statistics->snapshot()->dml->insert->affectedRows);
        self::assertSame(2, $statistics->snapshot()->sqlCount);
        self::assertCount(2, $events->events); // DML annotates SQL; no second completion event.
    }

    public function testFailedPreparationAndStaleMeasurementsDoNotBecomeExecutedDml(): void
    {
        $statistics = new Statistics(true);
        $connection = $this->createMock(Connection::class);
        $connection->method('prepare')->willThrowException(new \RuntimeException());
        try {
            (new StatisticsConnection($connection, $statistics))->prepare('INSERT INTO missing VALUES (?)');
        } catch (\RuntimeException) {
        }
        self::assertSame(1, $statistics->snapshot()->sqlErrors);
        self::assertSame(0, $statistics->snapshot()->dml->insert->count);
        $measurement = $statistics->start();
        $statistics->reset();
        $statistics->recordSql($measurement, false, DmlOperation::UPDATE, 3);
        self::assertSame(0, $statistics->snapshot()->dml->update->count);
        $statistics->disable();
        self::assertNull($statistics->start());
    }
}
