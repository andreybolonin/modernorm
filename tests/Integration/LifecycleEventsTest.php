<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Closure;
use Doctrine\Common\EventArgs;
use Doctrine\DBAL\DriverManager;
use ModernORM\EntityManager;
use ModernORM\Event\OnFlushEventArgs;
use ModernORM\Event\PreFlushEventArgs;
use ModernORM\Event\PrePersistEventArgs;
use ModernORM\Event\PreUpdateEventArgs;
use ModernORM\Events;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_event_records'), ORM\HasLifecycleCallbacks]
#[ORM\EntityListeners([RecordEntityListener::class])]
final class EventRecord
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column]
    public string $note = 'initial';

    /** @var list<string> */
    public array $trace = [];

    public function __construct(#[ORM\Column] public string $name = 'original')
    {
    }

    #[ORM\PrePersist]
    public function beforePersist(): void
    {
        $this->trace[] = 'callback';
    }
}

final class RecordEntityListener
{
    #[ORM\PrePersist]
    public function beforePersist(EventRecord $record, PrePersistEventArgs $args): void
    {
        $record->trace[] = 'entity-listener';
        $record->note = 'prepared';
    }
}

/** Dispatch named events to a test-specific handler without shared static state. */
final class LifecycleHandler
{
    public function __construct(private Closure $handler)
    {
    }

    public function __call(string $event, array $args): void
    {
        ($this->handler)($event, $args[0]);
    }
}

final class LifecycleEventsTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ]), ORMSetup::createAttributeMetadataConfig([__DIR__], true, cache: new ArrayAdapter()));
        $metadata = [$this->em->getClassMetadata(EventRecord::class)];
        $schema = new SchemaTool($this->em);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $connection->executeStatement('DROP TABLE IF EXISTS modernorm_test_event_records');
        $connection->close();
    }

    private function listen(array $events, Closure $handler): void
    {
        $this->em->getEventManager()->addEventListener($events, new LifecycleHandler($handler));
    }

    private function seed(): EventRecord
    {
        $record = new EventRecord();
        $this->em->persist($record);
        $this->em->flush();

        return $record;
    }

    public function testCallbackEntityListenerAndGlobalListenerOrder(): void
    {
        $this->listen([Events::prePersist], function (string $event, PrePersistEventArgs $args): void {
            self::assertSame($this->em, $args->getObjectManager());
            $record = $args->getObject();
            self::assertSame(['callback', 'entity-listener'], $record->trace);
            $record->trace[] = 'global-listener';
        });
        $record = $this->seed();
        self::assertSame(['callback', 'entity-listener', 'global-listener'], $record->trace);
        $id = $record->id;
        $this->em->clear();
        self::assertSame('prepared', $this->em->find(EventRecord::class, $id)->note);
    }

    public function testPreFlushMutationIsIncludedInChangeSet(): void
    {
        $record = $this->seed();
        $this->listen([Events::preFlush], function (string $event, PreFlushEventArgs $args) use ($record): void {
            self::assertSame($this->em, $args->getObjectManager());
            $record->name = 'from-preFlush';
        });
        $this->em->flush();
        self::assertSame('from-preFlush', $this->em->getUnitOfWork()->getOriginalEntityData($record)['name']);
        $this->em->clear();
        self::assertSame('from-preFlush', $this->em->find(EventRecord::class, $record->id)->name);
    }

    public function testOnFlushRecomputesUpdateAndComputesNewEntityChangeSet(): void
    {
        $record = $this->seed();
        $record->name = 'requested';
        $audit = null;
        $this->listen([Events::onFlush], function (string $event, OnFlushEventArgs $args) use ($record, &$audit): void {
            $em = $args->getObjectManager();
            $uow = $em->getUnitOfWork();
            self::assertContains($record, $uow->getScheduledEntityUpdates());
            self::assertSame(['original', 'requested'], $uow->getEntityChangeSet($record)['name']);
            $record->note = 'from-onFlush';
            $metadata = $em->getClassMetadata(EventRecord::class);
            $uow->recomputeSingleEntityChangeSet($metadata, $record);
            $audit = new EventRecord('audit');
            $em->persist($audit);
            $uow->computeChangeSet($metadata, $audit);
        });
        $this->em->flush();
        self::assertNotNull($audit->id);
        self::assertSame([], $this->em->getUnitOfWork()->getScheduledEntityInsertions());
        $this->em->clear();
        $loaded = $this->em->find(EventRecord::class, $record->id);
        self::assertSame('requested', $loaded->name);
        self::assertSame('from-onFlush', $loaded->note);
        self::assertSame('audit', $this->em->find(EventRecord::class, $audit->id)->name);
    }

    public function testPreUpdateCanChangeExistingChangeSetAndAdditionalScalar(): void
    {
        $record = $this->seed();
        $record->name = 'requested';
        $calls = 0;
        $this->listen([Events::preUpdate], function (string $event, PreUpdateEventArgs $args) use (&$calls): void {
            ++$calls;
            self::assertSame('original', $args->getOldValue('name'));
            self::assertSame('requested', $args->getNewValue('name'));
            self::assertFalse($args->hasChangedField('note'));
            $args->setNewValue('name', 'normalized');
            $args->getObject()->name = 'normalized';
            $args->getObject()->note = 'updated-by-listener';
        });
        $this->em->flush();
        self::assertSame(1, $calls);
        self::assertSame('normalized', $this->em->getUnitOfWork()->getOriginalEntityData($record)['name']);
        $this->em->flush();
        self::assertSame(1, $calls);
        $this->em->clear();
        $loaded = $this->em->find(EventRecord::class, $record->id);
        self::assertSame('normalized', $loaded->name);
        self::assertSame('updated-by-listener', $loaded->note);
    }

    public function testFlushEventsAlsoRunWithoutPendingChanges(): void
    {
        $record = $this->seed();
        $events = [];
        $this->listen(
            [Events::preFlush, Events::onFlush, Events::postFlush, Events::preUpdate, Events::postUpdate],
            function (string $event, EventArgs $args) use (&$events): void {
                $events[] = $event;
            }
        );
        $this->em->flush();
        self::assertSame(['preFlush', 'onFlush', 'postFlush'], $events);
        self::assertSame([], $this->em->getUnitOfWork()->getEntityChangeSet($record));
        self::assertTrue($this->em->isOpen());
    }

    public function testPostPersistFailureRollsBackClosesManagerAndSkipsPostFlush(): void
    {
        $events = [];
        $record = new EventRecord();
        $this->listen(
            [Events::postPersist, Events::postFlush],
            function (string $event, EventArgs $args) use (&$events, $record): void {
                $events[] = $event;
                if ($event === Events::postPersist) {
                    self::assertSame($record, $args->getObject());
                    self::assertNotNull($record->id);
                    self::assertTrue($this->em->getConnection()->isTransactionActive());
                    throw new RuntimeException('postPersist failed');
                }
            }
        );
        $this->em->persist($record);
        try {
            $this->em->flush();
            self::fail('Expected listener exception');
        } catch (RuntimeException $exception) {
            self::assertSame('postPersist failed', $exception->getMessage());
        }
        self::assertSame(['postPersist'], $events);
        self::assertFalse($this->em->isOpen());
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_event_records'));
        self::assertNotNull($record->id); // Rollback does not restore PHP state.
    }

    public function testPostFlushFailureDoesNotUndoCommittedInsert(): void
    {
        $record = new EventRecord();
        $this->listen([Events::postFlush], function (string $event, EventArgs $args): void {
            self::assertFalse($this->em->getConnection()->isTransactionActive());
            throw new RuntimeException('postFlush failed');
        });
        $this->em->persist($record);
        try {
            $this->em->flush();
            self::fail('Expected listener exception');
        } catch (RuntimeException $exception) {
            self::assertSame('postFlush failed', $exception->getMessage());
        }
        self::assertTrue($this->em->isOpen());
        self::assertNotNull($record->id);
        // In the absence of an outer transaction, commit precedes postFlush.
        self::assertSame('original', $this->em->getConnection()->fetchOne(
            'SELECT name FROM modernorm_test_event_records WHERE id = ?',
            [$record->id],
        ));
        // Verify committed database state independently of the managed instance.
        $this->em->clear();
        self::assertSame('original', $this->em->find(EventRecord::class, $record->id)->name);
    }

    public function testEmptyFlushPostFlushFailureCleansUpAndAllowsLaterUpdate(): void
    {
        $record = $this->seed();
        $calls = 0;
        $this->listen([Events::postFlush], function () use (&$calls): void {
            if (++$calls === 1) {
                throw new RuntimeException('empty postFlush failed');
            }
        });
        try {
            $this->em->flush();
            self::fail('Expected empty postFlush failure.');
        } catch (RuntimeException $error) {
            self::assertSame('empty postFlush failed', $error->getMessage());
        }
        self::assertTrue($this->em->isOpen());
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertSame([], $this->em->getUnitOfWork()->getEntityChangeSet($record));
        $record->note = 'after empty failure';
        $this->em->flush();
        self::assertSame(2, $calls);
        self::assertSame('after empty failure', $this->em->getConnection()->fetchOne(
            'SELECT note FROM modernorm_test_event_records WHERE id = ?',
            [$record->id],
        ));
    }

    public function testPostFlushFailureLeavesManualOuterTransactionUnderCallerControl(): void
    {
        $record = $this->seed();
        $connection = $this->em->getConnection();
        $original = $connection->fetchOne('SELECT note FROM modernorm_test_event_records WHERE id = ?', [$record->id]);
        $connection->beginTransaction();
        $this->listen([Events::postFlush], function () use ($connection): void {
            self::assertSame(1, $connection->getTransactionNestingLevel());
            throw new RuntimeException('outer postFlush failed');
        });
        try {
            $record->note = 'pending outer';
            try {
                $this->em->flush();
                self::fail('Expected postFlush failure inside the outer transaction.');
            } catch (RuntimeException $error) {
                self::assertSame('outer postFlush failed', $error->getMessage());
            }
            self::assertTrue($this->em->isOpen());
            self::assertTrue($connection->isTransactionActive());
            self::assertSame([], $this->em->getUnitOfWork()->getEntityChangeSet($record));
            self::assertSame('pending outer', $connection->fetchOne('SELECT note FROM modernorm_test_event_records WHERE id = ?', [$record->id]));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
        self::assertSame($original, $connection->fetchOne('SELECT note FROM modernorm_test_event_records WHERE id = ?', [$record->id]));
        self::assertSame('pending outer', $record->note);
        $this->em->clear();
        self::assertSame($original, $this->em->find(EventRecord::class, $record->id)->note);
    }

    public function testPreFlushFailureOccursBeforeTransactionAndLeavesManagerOpen(): void
    {
        $record = new EventRecord();
        $events = [];
        $this->listen(
            [Events::preFlush, Events::onFlush, Events::postFlush],
            function (string $event, EventArgs $args) use (&$events): void {
                $events[] = $event;
                if ($event === Events::preFlush) {
                    self::assertFalse($this->em->getConnection()->isTransactionActive());
                    throw new RuntimeException('preFlush failed');
                }
            }
        );
        $this->em->persist($record);
        try {
            $this->em->flush();
            self::fail('Expected listener exception');
        } catch (RuntimeException $exception) {
            self::assertSame('preFlush failed', $exception->getMessage());
        }
        self::assertSame(['preFlush'], $events);
        self::assertTrue($this->em->isOpen());
        self::assertContains($record, $this->em->getUnitOfWork()->getScheduledEntityInsertions());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_event_records'));
    }
}
