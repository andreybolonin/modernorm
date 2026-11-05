<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use ModernORM\EntityManager;
use ModernORM\Exception\EntityManagerClosed;
use ModernORM\Mapping as ORM;
use ModernORM\Exception\OptimisticLockException;
use ModernORM\ORMSetup;
use ModernORM\Tools\SchemaTool;
use ModernORM\Exception\TransactionRequiredException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_versioned')]
final class VersionedRecord
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column(unique: true)]
    public string $name;

    #[ORM\Version, ORM\Column(type: 'integer')]
    public int $version;

    #[ORM\ManyToOne(targetEntity: self::class)]
    public ?self $parent = null;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}

final class ConcurrencyTest extends TestCase
{
    private EntityManager $first;
    private EntityManager $second;

    private function manager(): EntityManager
    {
        return new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432,
            'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres',
            'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ]), ORMSetup::createAttributeMetadataConfig([__DIR__], true, cache: new ArrayAdapter()));
    }

    protected function setUp(): void
    {
        $this->first = $this->manager();
        $this->second = $this->manager();
        $schema = new SchemaTool($this->first);
        $metadata = [$this->first->getClassMetadata(VersionedRecord::class)];
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        foreach ([$this->second, $this->first] as $em) {
            while ($em->getConnection()->isTransactionActive()) {
                $em->getConnection()->rollBack();
            }
        }
        $this->first->getConnection()->executeStatement('DROP TABLE IF EXISTS modernorm_test_versioned');
        $this->first->getConnection()->close();
        $this->second->getConnection()->close();
    }

    private function seed(string $name = 'original'): VersionedRecord
    {
        $record = new VersionedRecord($name);
        $this->first->persist($record);
        $this->first->flush();
        return $record;
    }

    public function testOptimisticLockRejectsStaleWriterAndClosesItsManager(): void
    {
        $winner = $this->seed();
        $stale = $this->second->find(VersionedRecord::class, $winner->id);
        self::assertSame(1, $winner->version);
        self::assertSame(1, $stale->version);
        $winner->name = 'winner';
        $this->first->flush();
        self::assertSame(2, $winner->version);
        $stale->name = 'loser';
        try {
            $this->second->flush();
            self::fail('Expected optimistic conflict');
        } catch (OptimisticLockException $exception) {
            self::assertSame($stale, $exception->getEntity());
        }
        self::assertFalse($this->second->isOpen());
        self::assertFalse($this->second->getConnection()->isTransactionActive());
        self::assertSame('loser', $stale->name);
        self::assertSame('winner', $this->second->getConnection()->fetchOne('SELECT name FROM modernorm_test_versioned WHERE id = ?', [$winner->id]));
        $this->second->clear();
        $this->expectException(EntityManagerClosed::class);
        $this->second->flush();
    }

    public function testPessimisticLockRequiresTransaction(): void
    {
        $record = $this->seed();
        $this->expectException(TransactionRequiredException::class);
        $this->second->find(VersionedRecord::class, $record->id, LockMode::PESSIMISTIC_WRITE);
    }

    public function testWriteLockBlocksAnotherConnectionAndIsReleasedAfterCommit(): void
    {
        $record = $this->seed();
        $one = $this->first->getConnection();
        $two = $this->second->getConnection();
        self::assertNotSame($one->fetchOne('SELECT pg_backend_pid()'), $two->fetchOne('SELECT pg_backend_pid()'));
        $one->beginTransaction();
        $this->first->lock($record, LockMode::PESSIMISTIC_WRITE);
        $two->beginTransaction();
        $two->executeStatement("SET LOCAL lock_timeout = '150ms'");
        $two->executeStatement("SET LOCAL statement_timeout = '2s'");
        try {
            $this->second->find(VersionedRecord::class, $record->id, LockMode::PESSIMISTIC_WRITE);
            self::fail('Expected lock timeout');
        } catch (DriverException $exception) {
            self::assertSame('55P03', $exception->getSQLState());
        } finally {
            $two->rollBack();
        }
        $record->name = 'committed';
        $this->first->flush();
        $one->commit();
        $this->second->clear();
        $two->beginTransaction();
        $two->executeStatement("SET LOCAL lock_timeout = '150ms'");
        $loaded = $this->second->find(VersionedRecord::class, $record->id, LockMode::PESSIMISTIC_WRITE);
        self::assertSame('committed', $loaded->name);
        $two->commit();
    }

    public function testUniqueFailureRollsBackWholeFlushAndRequiresNewManager(): void
    {
        $existing = $this->seed();
        $existing->name = 'changed';
        $this->first->persist(new VersionedRecord('duplicate'));
        $this->first->persist(new VersionedRecord('duplicate'));
        try {
            $this->first->flush();
            self::fail('Expected unique violation');
        } catch (UniqueConstraintViolationException) {
            self::assertFalse($this->first->isOpen());
        }
        self::assertFalse($this->first->getConnection()->isTransactionActive());
        self::assertSame(1, (int) $this->second->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_versioned'));
        self::assertSame('original', $this->second->find(VersionedRecord::class, $existing->id)->name);
        self::assertSame('changed', $existing->name);
        self::assertFalse($this->first->contains($existing));
    }

    public function testForeignKeyFailureRollsBackAndClosesManager(): void
    {
        $record = new VersionedRecord('invalid-parent');
        $record->parent = $this->first->getReference(VersionedRecord::class, 2147483647);
        $this->first->persist($record);
        try {
            $this->first->flush();
            self::fail('Expected foreign key violation');
        } catch (ForeignKeyConstraintViolationException) {
            self::assertFalse($this->first->isOpen());
        }
        self::assertFalse($this->first->getConnection()->isTransactionActive());
        self::assertSame(0, (int) $this->second->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_versioned'));
    }

    public function testNestedDbalRollbackUsesSavepointAndOuterTransactionCanCommit(): void
    {
        $connection = $this->first->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement("INSERT INTO modernorm_test_versioned (name, version) VALUES ('outer', 1)");
        $connection->beginTransaction();
        self::assertSame(2, $connection->getTransactionNestingLevel());
        $connection->executeStatement("INSERT INTO modernorm_test_versioned (name, version) VALUES ('inner', 1)");
        $connection->rollBack();
        self::assertSame(1, $connection->getTransactionNestingLevel());
        self::assertSame(['outer'], $connection->fetchFirstColumn('SELECT name FROM modernorm_test_versioned'));
        self::assertSame(0, (int) $this->second->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_versioned'));
        $connection->commit();
        self::assertSame(['outer'], $this->second->getConnection()->fetchFirstColumn('SELECT name FROM modernorm_test_versioned'));
    }

    public function testOuterRollbackAfterSuccessfulFlushDoesNotRestorePhpState(): void
    {
        $record = $this->seed();
        $connection = $this->first->getConnection();
        $connection->beginTransaction();
        $record->name = 'temporary';
        $this->first->flush();
        self::assertSame(1, $connection->getTransactionNestingLevel());
        self::assertSame(2, $record->version);
        $connection->rollBack();
        self::assertTrue($this->first->isOpen());
        self::assertSame('temporary', $record->name);
        self::assertSame('temporary', $this->first->getUnitOfWork()->getOriginalEntityData($record)['name']);
        $this->first->clear();
        $reloaded = $this->first->find(VersionedRecord::class, $record->id);
        self::assertNotSame($record, $reloaded);
        self::assertSame('original', $reloaded->name);
        self::assertSame(1, $reloaded->version);
    }

    public function testBulkUpdateBypassesAutomaticVersionCheckAndIncrement(): void
    {
        $record = $this->seed();
        $stale = $this->second->find(VersionedRecord::class, $record->id);
        self::assertSame(1, $this->first->createQueryBuilder()->update('modernorm_test_versioned')->set('name', ':name')->where('id = :id')
            ->setParameter('name', 'bulk')->setParameter('id', $record->id)->executeStatement());
        self::assertSame(1, (int) $this->first->getConnection()->fetchOne('SELECT version FROM modernorm_test_versioned WHERE id = ?', [$record->id]));
        $stale->name = 'overwritten';
        $this->second->flush();
        self::assertSame(2, $stale->version);
        self::assertSame('overwritten', $this->first->getConnection()->fetchOne('SELECT name FROM modernorm_test_versioned WHERE id = ?', [$record->id]));
    }

    public function testBulkVersionGuardAndIncrementPreventLostUpdate(): void
    {
        $record = $this->seed();
        $stale = $this->second->find(VersionedRecord::class, $record->id);
        $query = $this->first->createQueryBuilder()->update('modernorm_test_versioned')->set('name', ':name')->set('version', 'version + 1')->where('id = :id AND version = :version')
            ->setParameter('name', 'guarded')->setParameter('id', $record->id)->setParameter('version', 1);
        self::assertSame(1, $query->executeStatement());
        self::assertSame(0, $query->executeStatement());
        $stale->name = 'loser';
        try {
            $this->second->flush();
            self::fail('Expected optimistic conflict');
        } catch (OptimisticLockException) {
            self::assertFalse($this->second->isOpen());
        }
        self::assertSame('guarded', $this->first->getConnection()->fetchOne('SELECT name FROM modernorm_test_versioned WHERE id = ?', [$record->id]));
    }
    public function testFailedFlushRollsBackItsSavepointAndLeavesOuterTransactionActive(): void
    {
        $connection = $this->first->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement("INSERT INTO modernorm_test_versioned (name, version) VALUES ('outer', 1)");
        $this->first->persist(new VersionedRecord('outer'));
        try {
            $this->first->flush();
            self::fail('Expected unique violation');
        } catch (UniqueConstraintViolationException) {
            self::assertFalse($this->first->isOpen());
        }
        self::assertSame(1, $connection->getTransactionNestingLevel());
        self::assertSame(['outer'], $connection->fetchFirstColumn('SELECT name FROM modernorm_test_versioned'));
        $connection->executeStatement("INSERT INTO modernorm_test_versioned (name, version) VALUES ('after-error', 1)");
        $connection->commit();
        self::assertSame(['after-error', 'outer'], $this->second->getConnection()->fetchFirstColumn('SELECT name FROM modernorm_test_versioned ORDER BY name'));
    }

}
