<?php

/*
 * This file is part of the ModernORM package.
 *
 * (c) Andrey Bolonin <andreybolonin1989@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use ModernORM\Cache\Persister\CachedPersister;
use ModernORM\EntityManagerInterface;
use ModernORM\Exception\CacheSynchronizationException;
use ModernORM\Internal\UnitOfWork\PersisterRegistry;
use ModernORM\Persisters\Entity\EntityPersister;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionProperty;
use RuntimeException;

final class PersisterRegistryTest extends TestCase
{
    private function cachedPersister(): EntityPersister&CachedPersister&MockObject
    {
        $persister = $this->createMockForIntersectionOfInterfaces([EntityPersister::class, CachedPersister::class]);
        self::assertInstanceOf(EntityPersister::class, $persister);
        self::assertInstanceOf(CachedPersister::class, $persister);
        return $persister;
    }

    /** @param list<EntityPersister&CachedPersister> $persisters */
    private function registry(array $persisters): PersisterRegistry
    {
        $registry = new PersisterRegistry($this->createMock(EntityManagerInterface::class), true);
        (new ReflectionProperty(PersisterRegistry::class, 'persisters'))->setValue($registry, $persisters);
        return $registry;
    }

    public function testCompletionVisitsEveryPersisterAndRethrowsTheFirstFailure(): void
    {
        $first = $this->cachedPersister();
        $second = $this->cachedPersister();
        $third = $this->cachedPersister();
        $failure = new RuntimeException('first failure');
        $first->expects(self::once())->method('afterTransactionComplete')->willThrowException($failure);
        $second->expects(self::once())->method('afterTransactionComplete')->willThrowException(new RuntimeException('second failure'));
        $third->expects(self::once())->method('afterTransactionComplete');

        try {
            $this->registry([$first, $second, $third])->afterTransactionComplete();
            self::fail('The first completion failure must propagate after visiting every persister.');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
        }
    }

    public function testRollbackKeepsItsFailFastCallbackOrder(): void
    {
        $first = $this->cachedPersister();
        $second = $this->cachedPersister();
        $failure = new RuntimeException('rollback failure');
        $first->expects(self::once())->method('afterTransactionRolledBack')->willThrowException($failure);
        $second->expects(self::never())->method('afterTransactionRolledBack');

        try {
            $this->registry([$first, $second])->afterTransactionRolledBack();
            self::fail('Rollback callback failure must propagate.');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
        }
    }

    public function testUncertainCommitInvalidatesEveryPersisterBeforeRollbackAndPreservesBothCauses(): void
    {
        $first = $this->cachedPersister();
        $second = $this->cachedPersister();
        $commitFailure = new RuntimeException('commit failed');
        $cacheFailure = new RuntimeException('invalidation failed');
        $trace = [];
        $first->expects(self::once())->method('invalidatePendingWrites')->willReturnCallback(static function () use (&$trace, $cacheFailure): bool {
            $trace[] = 'invalidate first';
            throw $cacheFailure;
        });
        $second->expects(self::once())->method('invalidatePendingWrites')->willReturnCallback(static function () use (&$trace): bool {
            $trace[] = 'invalidate second';
            return true;
        });
        $first->expects(self::once())->method('afterTransactionRolledBack')->willReturnCallback(static function () use (&$trace): void {
            $trace[] = 'rollback first';
        });
        $second->expects(self::once())->method('afterTransactionRolledBack')->willReturnCallback(static function () use (&$trace): void {
            $trace[] = 'rollback second';
        });

        try {
            $this->registry([$first, $second])->afterTransactionCommitFailed($commitFailure);
            self::fail('An uncertain commit with affected cache keys requires explicit recovery.');
        } catch (CacheSynchronizationException $error) {
            self::assertSame($commitFailure, $error->getPrevious());
            self::assertSame($cacheFailure, $error->cacheFailure);
            self::assertNull($error->databaseCommitted);
        }
        self::assertSame(['invalidate first', 'invalidate second', 'rollback first', 'rollback second'], $trace);
    }

    public function testUncertainCommitWithoutPendingCacheWritesOnlyRunsRollbackCleanup(): void
    {
        $persister = $this->cachedPersister();
        $persister->expects(self::once())->method('invalidatePendingWrites')->willReturn(false);
        $persister->expects(self::once())->method('afterTransactionRolledBack');

        $this->registry([$persister])->afterTransactionCommitFailed(new RuntimeException('commit failed'));
    }
}
