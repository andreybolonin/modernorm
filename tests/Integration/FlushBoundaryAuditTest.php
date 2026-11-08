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

namespace ModernORM\Tests\Integration;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use ModernORM\EntityManager;
use ModernORM\Event\PreUpdateEventArgs;
use ModernORM\Events;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Query\ResultSetMapping;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_flush_boundaries')]
final class FlushBoundaryRecord
{
    #[ORM\Column(type: 'datetime_immutable')]
    public DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Id, ORM\Column] public string $id,
        #[ORM\Column(unique: true)] public string $name,
        #[ORM\Column(nullable: true)] public ?string $note = null,
    ) {
        $this->createdAt = new DateTimeImmutable('2026-01-01 12:00:00');
    }
}

final class FlushBoundaryListener
{
    public function __construct(private Closure $callback)
    {
    }

    public function __call(string $event, array $args): void
    {
        ($this->callback)($args[0]);
    }
}

final class FlushBoundaryAuditTest extends TestCase
{
    private EntityManager $em;
    private Statistics $statistics;

    protected function setUp(): void
    {
        $this->statistics = new Statistics(enabled: true);
        $dbal = new Configuration();
        $dbal->setMiddlewares([new Middleware($this->statistics)]);
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $dbal), ORMSetup::createAttributeMetadataConfig([__DIR__], true, cache: new ArrayAdapter()));
        $schema = new SchemaTool($this->em);
        $metadata = [$this->em->getClassMetadata(FlushBoundaryRecord::class)];
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $connection->executeStatement('DROP TABLE IF EXISTS modernorm_test_flush_boundaries');
        $connection->close();
    }

    private function seed(string $id = 'a', string $name = 'original'): FlushBoundaryRecord
    {
        $record = new FlushBoundaryRecord($id, $name);
        $this->em->persist($record);
        $this->em->flush();
        return $record;
    }

    private function listen(string $event, Closure $callback): void
    {
        $this->em->getEventManager()->addEventListener([$event], new FlushBoundaryListener($callback));
    }

    private function assertQueuesEmpty(): void
    {
        $uow = $this->em->getUnitOfWork();
        self::assertSame([], $uow->getScheduledEntityInsertions());
        self::assertSame([], $uow->getScheduledEntityUpdates());
        self::assertSame([], $uow->getScheduledEntityDeletions());
        self::assertSame([], $uow->getScheduledCollectionUpdates());
        self::assertSame([], $uow->getScheduledCollectionDeletions());
    }

    private function assertManagedResult(FlushBoundaryRecord $record, string $sqlName, string $phpName, ?string $note): void
    {
        $uow = $this->em->getUnitOfWork();
        self::assertTrue($this->em->isOpen());
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertSame($record, $this->em->find(FlushBoundaryRecord::class, $record->id));
        self::assertSame($phpName, $record->name);
        self::assertSame($note, $record->note);
        self::assertSame($phpName, $uow->getOriginalEntityData($record)['name']);
        self::assertSame($note, $uow->getOriginalEntityData($record)['note']);
        self::assertSame([], $uow->getEntityChangeSet($record));
        self::assertSame(['name' => $sqlName, 'note' => $note], $this->em->getConnection()->fetchAssociative(
            'SELECT name, note FROM modernorm_test_flush_boundaries WHERE id = ?',
            [$record->id],
        ));
        $this->assertQueuesEmpty();
    }

    private function assertNextFlushDoesNoDml(): void
    {
        $this->statistics->reset();
        $this->em->flush();
        $dml = $this->statistics->snapshot()->dml;
        self::assertSame(0, $dml->insert->count);
        self::assertSame(0, $dml->update->count);
        self::assertSame(0, $dml->delete->count);
        $this->assertQueuesEmpty();
    }

    public function testPreUpdateReversionAndAdditionalFieldHaveOneConsistentSnapshot(): void
    {
        $record = $this->seed();
        $record->name = 'requested';
        $calls = 0;
        $this->listen(Events::preUpdate, static function (PreUpdateEventArgs $args) use ($record, &$calls): void {
            ++$calls;
            self::assertSame('original', $args->getOldValue('name'));
            self::assertSame('requested', $args->getNewValue('name'));
            $record->name = 'original';
            $record->note = 'callback';
        });
        $this->em->flush();
        $this->assertManagedResult($record, 'original', 'original', 'callback');
        $this->assertNextFlushDoesNoDml();
        self::assertSame(1, $calls);
    }

    public function testSetNewValueOverridesSqlWithoutImplicitlyChangingTheObject(): void
    {
        $record = $this->seed();
        $record->name = 'requested';
        $calls = 0;
        $this->listen(Events::preUpdate, static function (PreUpdateEventArgs $args) use ($record, &$calls): void {
            ++$calls;
            $args->setNewValue('name', 'sql-normalized');
            $record->note = 'callback';
        });
        $this->em->flush();
        $this->assertManagedResult($record, 'sql-normalized', 'requested', 'callback');
        $this->assertNextFlushDoesNoDml();
        self::assertSame(1, $calls);
        $this->em->refresh($record);
        $this->assertManagedResult($record, 'sql-normalized', 'sql-normalized', 'callback');
    }

    public function testOnFlushFailureKeepsExplicitWorkForRetryBeforeAnyTransaction(): void
    {
        $record = $this->seed();
        $added = new FlushBoundaryRecord('b', 'added');
        $calls = 0;
        $this->listen(Events::onFlush, function () use ($record, $added, &$calls): void {
            if (++$calls !== 1) {
                return;
            }
            $record->note = 'scheduled';
            $uow = $this->em->getUnitOfWork();
            $metadata = $this->em->getClassMetadata(FlushBoundaryRecord::class);
            $uow->recomputeSingleEntityChangeSet($metadata, $record);
            $this->em->persist($added);
            $uow->computeChangeSet($metadata, $added);
            throw new RuntimeException('onFlush failed');
        });
        try {
            $this->em->flush();
            self::fail('Expected onFlush failure.');
        } catch (RuntimeException $error) {
            self::assertSame('onFlush failed', $error->getMessage());
        }
        $uow = $this->em->getUnitOfWork();
        self::assertTrue($this->em->isOpen());
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertContains($record, $uow->getScheduledEntityUpdates());
        self::assertContains($added, $uow->getScheduledEntityInsertions());
        self::assertSame('scheduled', $record->note);
        self::assertSame('scheduled', $uow->getOriginalEntityData($record)['note']);
        self::assertSame([null, 'scheduled'], $uow->getEntityChangeSet($record)['note']);
        self::assertNull($this->em->getConnection()->fetchOne('SELECT note FROM modernorm_test_flush_boundaries WHERE id = ?', ['a']));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM modernorm_test_flush_boundaries'));
        $this->em->flush();
        $this->assertManagedResult($record, 'original', 'original', 'scheduled');
        $this->assertManagedResult($added, 'added', 'added', null);
        $this->assertNextFlushDoesNoDml();
    }

    public function testPostFlushFailureKeepsLateFieldChangeForTheNextCycle(): void
    {
        $record = $this->seed();
        $record->name = 'committed';
        $calls = 0;
        $this->listen(Events::postFlush, static function () use ($record, &$calls): void {
            if (++$calls === 1) {
                $record->name = 'late-change';
                throw new RuntimeException('after commit');
            }
        });
        try {
            $this->em->flush();
            self::fail('Expected postFlush failure.');
        } catch (RuntimeException $error) {
            self::assertSame('after commit', $error->getMessage());
        }
        self::assertTrue($this->em->isOpen());
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertSame('late-change', $record->name);
        self::assertSame('committed', $this->em->getUnitOfWork()->getOriginalEntityData($record)['name']);
        self::assertSame([], $this->em->getUnitOfWork()->getEntityChangeSet($record));
        self::assertSame('committed', $this->em->getConnection()->fetchOne('SELECT name FROM modernorm_test_flush_boundaries WHERE id = ?', ['a']));
        $this->assertQueuesEmpty();
        $this->em->flush();
        $this->assertManagedResult($record, 'late-change', 'late-change', null);
        $this->assertNextFlushDoesNoDml();
    }

    public function testOnFlushLazyInitializationPreservesLocalValueAndExplicitRecompute(): void
    {
        $this->seed();
        $this->em->clear();
        $mapping = new ResultSetMapping();
        $mapping->addEntityResult(FlushBoundaryRecord::class, 'r');
        $mapping->addFieldResult('r', 'id', 'id');
        $mapping->addFieldResult('r', 'note', 'note');
        $mapping->partialAliases['r'] = true;
        $record = $this->em->createNativeQuery('SELECT id, note FROM modernorm_test_flush_boundaries', $mapping)->getSingleResult();
        self::assertInstanceOf(FlushBoundaryRecord::class, $record);
        $record->note = 'local-before-init';
        self::assertTrue($this->em->isUninitializedObject($record));
        $calls = 0;
        $this->listen(Events::onFlush, function () use ($record, &$calls): void {
            if (++$calls !== 1) {
                return;
            }
            self::assertSame('original', $record->name);
            self::assertSame('local-before-init', $record->note);
            self::assertNull($this->em->getUnitOfWork()->getOriginalEntityData($record)['note']);
            $this->em->getUnitOfWork()->recomputeSingleEntityChangeSet($this->em->getClassMetadata(FlushBoundaryRecord::class), $record);
        });
        $this->em->flush();
        $this->assertManagedResult($record, 'original', 'original', 'local-before-init');
        $this->assertNextFlushDoesNoDml();
    }

    public function testSqlFailureAfterAnEarlierUpdateRollsBackAndClearsTracking(): void
    {
        $first = $this->seed('a', 'first');
        $second = $this->seed('b', 'second');
        $first->name = 'conflict';
        $second->name = 'conflict';
        $this->statistics->reset();
        try {
            $this->em->flush();
            self::fail('Expected a unique constraint failure.');
        } catch (UniqueConstraintViolationException) {
        }
        self::assertSame(2, $this->statistics->snapshot()->dml->update->count);
        self::assertSame(1, $this->statistics->snapshot()->dml->update->errors);
        self::assertSame(['first', 'second'], $this->em->getConnection()->fetchFirstColumn('SELECT name FROM modernorm_test_flush_boundaries ORDER BY id'));
        self::assertSame('conflict', $first->name);
        self::assertSame('conflict', $second->name);
        self::assertFalse($this->em->isOpen());
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertSame(0, $this->em->getUnitOfWork()->size());
        self::assertSame([], $this->em->getUnitOfWork()->getOriginalEntityData($first));
        self::assertSame([], $this->em->getUnitOfWork()->getOriginalEntityData($second));
        self::assertSame([], $this->em->getUnitOfWork()->getEntityChangeSet($first));
        $this->assertQueuesEmpty();
    }

    public function testPostUpdateFailureRollsBackSqlAndClearsSnapshotsAndQueues(): void
    {
        $record = $this->seed();
        $record->note = 'attempted';
        $this->listen(Events::postUpdate, static function (): void {
            throw new RuntimeException('postUpdate failed');
        });
        try {
            $this->em->flush();
            self::fail('Expected postUpdate failure.');
        } catch (RuntimeException $error) {
            self::assertSame('postUpdate failed', $error->getMessage());
        }
        self::assertNull($this->em->getConnection()->fetchOne('SELECT note FROM modernorm_test_flush_boundaries WHERE id = ?', ['a']));
        self::assertSame('attempted', $record->note);
        self::assertFalse($this->em->isOpen());
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertSame([], $this->em->getUnitOfWork()->getOriginalEntityData($record));
        self::assertSame([], $this->em->getUnitOfWork()->getEntityChangeSet($record));
        self::assertSame(0, $this->em->getUnitOfWork()->size());
        $this->assertQueuesEmpty();
    }
}
