<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use ModernORM\Internal\UnitOfWork\FlushExecutor;
use ModernORM\Internal\UnitOfWork\FlushOperationQueues;
use ModernORM\Internal\UnitOfWork\InsertBatch;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Persisters\Entity\EntityPersister;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class FlushExecutorTest extends TestCase
{
    public function testPersisterSeesQueueAcknowledgementsBeforeExecutingInsertBatch(): void
    {
        $entities = [new stdClass(), new stdClass()];
        $queues = new FlushOperationQueues();
        foreach ($entities as $entity) {
            $queues->addInsertion($entity);
        }
        $persister = $this->createMock(EntityPersister::class);
        $added = [];
        $persister->expects(self::exactly(2))->method('addInsert')->willReturnCallback(static function (object $entity) use (&$added, $queues): void {
            self::assertTrue($queues->hasInsertion(spl_object_id($entity)));
            $added[] = $entity;
        });
        $persister->expects(self::once())->method('executeInserts')->willReturnCallback(static function () use (&$added, $entities, $queues): void {
            self::assertSame($entities, $added);
            self::assertSame([], $queues->insertions());
        });
        FlushExecutor::executeInsertBatch(
            new InsertBatch(new ClassMetadata(stdClass::class), $entities),
            $persister,
            static function (object $entity) use ($queues): void {
                $queues->removeInsertion(spl_object_id($entity));
            }
        );
    }

    public static function failedQueuePositions(): array
    {
        return ['first entity' => [0], 'second entity' => [1]];
    }

    #[DataProvider('failedQueuePositions')]
    public function testFailedAddDoesNotAcknowledgeFailedOrLaterEntities(int $failAt): void
    {
        $entities = [new stdClass(), new stdClass(), new stdClass()];
        $failure = new RuntimeException('queue failed');
        $persister = $this->createMock(EntityPersister::class);
        $calls = 0;
        $acknowledged = [];
        $persister->expects(self::exactly($failAt + 1))->method('addInsert')->willReturnCallback(static function () use (&$calls, $failAt, $failure): void {
            if ($calls++ === $failAt) {
                throw $failure;
            }
        });
        $persister->expects(self::never())->method('executeInserts');
        try {
            FlushExecutor::executeInsertBatch(
                new InsertBatch(new ClassMetadata(stdClass::class), $entities),
                $persister,
                static function (object $entity) use (&$acknowledged): void {
                    $acknowledged[] = $entity;
                }
            );
            self::fail('Queue failure must propagate.');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
            self::assertSame(array_slice($entities, 0, $failAt), $acknowledged);
        }
    }

    public function testExecutionFailurePropagatesAfterAllAcknowledgementsWithoutRetry(): void
    {
        $entities = [new stdClass(), new stdClass()];
        $failure = new RuntimeException('SQL failed');
        $persister = $this->createMock(EntityPersister::class);
        $acknowledged = [];
        $persister->expects(self::exactly(2))->method('addInsert');
        $persister->expects(self::once())->method('executeInserts')->willReturnCallback(static function () use (&$acknowledged, $entities, $failure): void {
            self::assertSame($entities, $acknowledged);
            throw $failure;
        });
        try {
            FlushExecutor::executeInsertBatch(
                new InsertBatch(new ClassMetadata(stdClass::class), $entities),
                $persister,
                static function (object $entity) use (&$acknowledged): void {
                    $acknowledged[] = $entity;
                }
            );
            self::fail('SQL failure must propagate.');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
        }
    }

    public function testAcknowledgementFailureStopsBeforeExecutingOrQueuingLaterEntities(): void
    {
        $entities = [new stdClass(), new stdClass()];
        $failure = new RuntimeException('acknowledgement failed');
        $persister = $this->createMock(EntityPersister::class);
        $persister->expects(self::once())->method('addInsert')->with($entities[0]);
        $persister->expects(self::never())->method('executeInserts');
        try {
            FlushExecutor::executeInsertBatch(
                new InsertBatch(new ClassMetadata(stdClass::class), $entities),
                $persister,
                static function () use ($failure): void {
                    throw $failure;
                }
            );
            self::fail('Acknowledgement failure must propagate.');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
        }
    }

    public function testUpdatesPrepareAndCompleteOneOperationAtATime(): void
    {
        $first = new stdClass();
        $second = new stdClass();
        $trace = [];
        $persister = $this->createMock(EntityPersister::class);
        $persister->expects(self::exactly(2))->method('update')->willReturnCallback(static function (object $entity) use (&$trace, $first): void {
            $trace[] = $entity === $first ? 'SQL first' : 'SQL second';
        });
        $operations = static function () use (&$trace, $first, $second, $persister): \Generator {
            $trace[] = 'prepare first';
            yield [$first, $persister];
            $trace[] = 'complete first';
            $trace[] = 'prepare second';
            yield [$second, $persister];
            $trace[] = 'complete second';
        };
        FlushExecutor::executeUpdates($operations());
        self::assertSame(['prepare first', 'SQL first', 'complete first', 'prepare second', 'SQL second', 'complete second'], $trace);
    }

    public function testUpdateFailureDoesNotResumeCoordinatorOrPrepareLaterWrites(): void
    {
        $failure = new RuntimeException('update failed');
        $first = new stdClass();
        $persister = $this->createMock(EntityPersister::class);
        $persister->expects(self::once())->method('update')->with($first)->willThrowException($failure);
        $trace = [];
        $operations = static function () use (&$trace, $first, $persister): \Generator {
            $trace[] = 'prepare first';
            yield [$first, $persister];
            $trace[] = 'complete first';
            yield [new stdClass(), $persister];
        };
        try {
            FlushExecutor::executeUpdates($operations());
            self::fail('Update failure must propagate.');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
            self::assertSame(['prepare first'], $trace);
        }
    }


    public function testDeletionsCompleteBeforePreparingTheNextIdentityRemoval(): void
    {
        $first = new stdClass();
        $second = new stdClass();
        $trace = [];
        $persister = $this->createMock(EntityPersister::class);
        $persister->expects(self::exactly(2))->method('delete')->willReturnCallback(static function (object $entity) use (&$trace, $first): bool {
            $trace[] = $entity === $first ? 'DELETE first' : 'DELETE second';
            return true;
        });
        $operations = static function () use (&$trace, $first, $second, $persister): \Generator {
            $trace[] = 'remove identity first';
            yield [$first, $persister];
            $trace[] = 'clean first';
            $trace[] = 'remove identity second';
            yield [$second, $persister];
            $trace[] = 'clean second';
            $trace[] = 'postRemove';
        };
        FlushExecutor::executeDeletions($operations());
        self::assertSame(['remove identity first', 'DELETE first', 'clean first', 'remove identity second', 'DELETE second', 'clean second', 'postRemove'], $trace);
    }

    public function testDeleteFailureStopsBeforeStateCleanupAndLaterOperations(): void
    {
        $failure = new RuntimeException('delete failed');
        $first = new stdClass();
        $persister = $this->createMock(EntityPersister::class);
        $persister->expects(self::once())->method('delete')->with($first)->willThrowException($failure);
        $trace = [];
        $operations = static function () use (&$trace, $first, $persister): \Generator {
            $trace[] = 'remove identity first';
            yield [$first, $persister];
            $trace[] = 'clean first';
            $trace[] = 'remove identity second';
            yield [new stdClass(), $persister];
            $trace[] = 'postRemove';
        };
        try {
            FlushExecutor::executeDeletions($operations());
            self::fail('Delete failure must propagate.');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
            self::assertSame(['remove identity first'], $trace);
        }
    }

}
