<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Exception\EntityNotFoundException;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_batch_targets')]
#[ORM\HasLifecycleCallbacks]
final class BatchTarget
{
    public static array $loaded = [];
    public function __construct(
        #[ORM\Id, ORM\Column] public int $id,
        #[ORM\Column] public string $name,
    ) {}
    #[ORM\PostLoad]
    public function loaded(): void { self::$loaded[] = $this->id; }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_batch_owners')]
final class BatchOwner
{
    public function __construct(
        #[ORM\Id, ORM\Column] public int $id,
        #[ORM\ManyToOne(targetEntity: BatchTarget::class)]
        #[ORM\JoinColumn(name: 'target_id', nullable: false)] public BatchTarget $target,
    ) {}
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_batch_text_targets')]
final class BatchTextTarget
{
    public function __construct(
        #[ORM\Id, ORM\Column(length: 32)] public string $code,
        #[ORM\Column] public string $name,
    ) {}
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_batch_text_owners')]
final class BatchTextOwner
{
    public function __construct(
        #[ORM\Id, ORM\Column] public int $id,
        #[ORM\ManyToOne(targetEntity: BatchTextTarget::class)]
        #[ORM\JoinColumn(name: 'target_code', referencedColumnName: 'code', nullable: false)] public BatchTextTarget $target,
    ) {}
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_batch_owners')]
final class BatchTwoOwner
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToOne(targetEntity: BatchTarget::class, batchSize: 2)]
    #[ORM\JoinColumn(name: 'target_id')] public BatchTarget $target;
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_batch_owners')]
final class BatchFourOwner
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToOne(targetEntity: BatchTarget::class, batchSize: 4)]
    #[ORM\JoinColumn(name: 'target_id')] public BatchTarget $target;
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_batch_owners')]
final class BatchDisabledOwner
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToOne(targetEntity: BatchTarget::class, batchSize: 1)]
    #[ORM\JoinColumn(name: 'target_id')] public BatchTarget $target;
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_batch_owners')]
final class BatchEagerOwner
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToOne(targetEntity: BatchTarget::class, fetch: 'EAGER', batchSize: 2)]
    #[ORM\JoinColumn(name: 'target_id')] public BatchTarget $target;
}

final class BatchFetchingTest extends TestCase
{
    private EntityManager $em;
    private Statistics $statistics;

    protected function setUp(): void
    {
        $this->statistics = new Statistics(enabled: true);
        $dbal = new DBALConfiguration();
        $dbal->setMiddlewares([new Middleware($this->statistics)]);
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $config->setLazyFetchBatchSize(3);
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $dbal), $config);
        $connection = $this->em->getConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS modernorm_test_batch_owners, modernorm_test_batch_targets, modernorm_test_batch_text_owners, modernorm_test_batch_text_targets');
        // Deliberately no FK: missing targets exercise the same failure as dangling external references.
        $connection->executeStatement('CREATE TABLE modernorm_test_batch_targets (id INT PRIMARY KEY, name VARCHAR(255) NOT NULL)');
        $connection->executeStatement('CREATE TABLE modernorm_test_batch_owners (id INT PRIMARY KEY, target_id INT NOT NULL)');
        for ($id = 1; $id <= 7; ++$id) {
            $connection->insert('modernorm_test_batch_targets', ['id' => $id, 'name' => 'target-' . $id]);
            $connection->insert('modernorm_test_batch_owners', ['id' => $id, 'target_id' => $id]);
        }
        $connection->executeStatement('CREATE TABLE modernorm_test_batch_text_targets (code VARCHAR(32) PRIMARY KEY, name VARCHAR(255) NOT NULL)');
        $connection->executeStatement('CREATE TABLE modernorm_test_batch_text_owners (id INT PRIMARY KEY, target_code VARCHAR(32) NOT NULL)');
        foreach (['01', '1', 'a b'] as $index => $code) {
            $connection->insert('modernorm_test_batch_text_targets', ['code' => $code, 'name' => 'text-' . $code]);
            $connection->insert('modernorm_test_batch_text_owners', ['id' => $index + 1, 'target_code' => $code]);
        }
        BatchTarget::$loaded = [];
        $this->statistics->reset();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DROP TABLE IF EXISTS modernorm_test_batch_owners, modernorm_test_batch_targets, modernorm_test_batch_text_owners, modernorm_test_batch_text_targets');
        $this->em->getConnection()->close();
    }

    private function owners(): array
    {
        return $this->em->getRepository(BatchOwner::class)->findBy([], ['id' => 'ASC']);
    }

    public function testSevenReferencesLoadInThreeBatchesAndKeepIdentityAndSnapshots(): void
    {
        $owners = $this->owners();
        foreach ($owners as $owner) {
            self::assertTrue($this->em->isUninitializedObject($owner->target));
        }
        $this->statistics->reset();
        foreach ($owners as $owner) {
            self::assertSame('target-' . $owner->id, $owner->target->name);
            self::assertSame($owner->target, $this->em->find(BatchTarget::class, $owner->id));
        }
        self::assertSame(3, $this->statistics->snapshot()->sqlCount);
        self::assertEqualsCanonicalizing(range(1, 7), BatchTarget::$loaded);
        $owners[1]->target->name = 'changed';
        $this->em->flush();
        self::assertSame('changed', $this->em->getConnection()->fetchOne('SELECT name FROM modernorm_test_batch_targets WHERE id = 2'));
    }

    public function testTriggerNeedNotBeFirstQueuedReference(): void
    {
        $owners = $this->owners();
        $this->statistics->reset();
        self::assertSame('target-6', $owners[5]->target->name);
        self::assertFalse($this->em->isUninitializedObject($owners[0]->target));
        self::assertFalse($this->em->isUninitializedObject($owners[1]->target));
        self::assertTrue($this->em->isUninitializedObject($owners[2]->target));
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
    }

    public function testAlreadyLoadedEntityAndLocalChangeAreNotRefreshed(): void
    {
        $owners = $this->owners();
        // Hydrate one queued peer separately, then modify it before another peer triggers a batch.
        $loaded = $this->em->getRepository(BatchTarget::class)->findBy(['id' => 1])[0];
        $loaded->name = 'local';
        $this->statistics->reset();
        self::assertSame($loaded, $owners[0]->target);
        self::assertSame('target-2', $owners[1]->target->name);
        self::assertSame('local', $loaded->name);
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
    }

    public function testRepeatedForeignKeysShareOneQueuedObject(): void
    {
        $this->em->getConnection()->executeStatement('UPDATE modernorm_test_batch_owners SET target_id = 1');
        $owners = $this->owners();
        $this->statistics->reset();
        foreach ($owners as $owner) {
            self::assertSame($owners[0]->target, $owner->target);
            self::assertSame('target-1', $owner->target->name);
        }
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        self::assertSame([1], BatchTarget::$loaded);
    }

    public function testMissingTriggerThrowsAndOtherBatchRowsRemainUsable(): void
    {
        $owners = $this->owners();
        $this->em->getConnection()->delete('modernorm_test_batch_targets', ['id' => 1]);
        $this->statistics->reset();
        try {
            $owners[0]->target->name;
            self::fail('Expected missing target');
        } catch (EntityNotFoundException) {
            self::assertTrue($this->em->isUninitializedObject($owners[0]->target));
        }
        self::assertSame('target-2', $owners[1]->target->name);
        self::assertSame('target-3', $owners[2]->target->name);
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
    }

    public function testMissingPeerFailsOnlyWhenAccessed(): void
    {
        $owners = $this->owners();
        $this->em->getConnection()->delete('modernorm_test_batch_targets', ['id' => 2]);
        self::assertSame('target-1', $owners[0]->target->name);
        self::assertTrue($this->em->isUninitializedObject($owners[1]->target));
        $this->expectException(EntityNotFoundException::class);
        $owners[1]->target->name;
    }

    public static function lifecycleActions(): array { return [['clear', false], ['close', false], ['detach', false], ['clear', true], ['close', true], ['detach', true]]; }

    #[DataProvider('lifecycleActions')]
    public function testLifecycleRemovesOldReferencesFromBatch(string $action, bool $l2): void
    {
        if ($l2) { $this->enableL2(); }
        $owners = $this->owners();
        if ($action === 'detach') {
            $this->em->detach($owners[0]->target);
            $this->statistics->reset();
            self::assertSame('target-2', $owners[1]->target->name);
            self::assertTrue($this->em->isUninitializedObject($owners[0]->target));
            self::assertFalse($this->em->contains($owners[0]->target));
        } else {
            $this->em->$action();
            $this->statistics->reset();
            // Detached ghosts retain the pre-existing single-row lazy-loading behavior.
            self::assertSame('target-1', $owners[0]->target->name);
            self::assertTrue($this->em->isUninitializedObject($owners[1]->target));
        }
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
    }

    public function testSingleRowModeRemainsAvailable(): void
    {
        $this->em->getConfiguration()->setLazyFetchBatchSize(1);
        $owners = $this->owners();
        $this->statistics->reset();
        foreach ($owners as $owner) {
            self::assertSame('target-' . $owner->id, $owner->target->name);
        }
        self::assertSame(7, $this->statistics->snapshot()->sqlCount);
    }

    public function testStringIdentifiersAndClassQueuesStaySeparate(): void
    {
        $owners = $this->owners();
        $textOwners = $this->em->getRepository(BatchTextOwner::class)->findBy([], ['id' => 'ASC']);
        $this->statistics->reset();
        self::assertSame('target-1', $owners[0]->target->name);
        foreach ($textOwners as $owner) {
            self::assertTrue($this->em->isUninitializedObject($owner->target));
        }
        foreach (['01', '1', 'a b'] as $index => $code) {
            self::assertSame('text-' . $code, $textOwners[$index]->target->name);
            self::assertSame($textOwners[$index]->target, $this->em->find(BatchTextTarget::class, $code));
        }
        self::assertSame(2, $this->statistics->snapshot()->sqlCount);
    }

    public function testPartialEntityIsExcludedFromPeerBatch(): void
    {
        $mapping = new \ModernORM\Query\ResultSetMapping();
        $mapping->addEntityResult(BatchTarget::class, 't');
        $mapping->addFieldResult('t', 'id', 'id');
        $mapping->partialAliases['t'] = true;
        $partial = $this->em->createNativeQuery('SELECT id FROM modernorm_test_batch_targets WHERE id = 1', $mapping)->getSingleResult();
        $owners = $this->owners();
        self::assertSame($partial, $owners[0]->target);
        $this->statistics->reset();
        self::assertSame('target-2', $owners[1]->target->name);
        self::assertTrue($this->em->isUninitializedObject($partial));
        self::assertSame('target-1', $partial->name);
        self::assertSame(2, $this->statistics->snapshot()->sqlCount);
    }

    public function testReferencesFromAnotherManagerAreNotBatched(): void
    {
        $firstOwners = $this->owners();
        $other = new EntityManager($this->em->getConnection(), $this->em->getConfiguration());
        $otherOwners = $other->getRepository(BatchOwner::class)->findBy([], ['id' => 'ASC']);
        $this->statistics->reset();
        self::assertSame('target-1', $otherOwners[0]->target->name);
        self::assertTrue($this->em->isUninitializedObject($firstOwners[0]->target));
        self::assertTrue($this->em->isUninitializedObject($firstOwners[1]->target));
        self::assertNotSame($firstOwners[0]->target, $otherOwners[0]->target);
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
    }

    public function testL2EnabledManagerBatchesUncachedReferences(): void
    {
        $config = clone $this->em->getConfiguration();
        $config->setSecondLevelCache(new \Symfony\Component\Cache\Adapter\ArrayAdapter());
        $other = new EntityManager($this->em->getConnection(), $config);
        $owners = $other->getRepository(BatchOwner::class)->findBy([], ['id' => 'ASC']);
        $this->statistics->reset();
        self::assertSame('target-1', $owners[0]->target->name);
        self::assertFalse($other->isUninitializedObject($owners[1]->target));
        self::assertTrue($other->isUninitializedObject($owners[3]->target));
        self::assertSame($owners[1]->target, $other->find(BatchTarget::class, 2));
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        foreach ($owners as $owner) { self::assertSame('target-' . $owner->id, $owner->target->name); }
        self::assertSame(3, $this->statistics->snapshot()->sqlCount);
    }

    public function testAttributesEnableBatchingWithoutGlobalOptInAndKeepSizesSeparate(): void
    {
        $this->em->getConfiguration()->setLazyFetchBatchSize(1);
        $two = $this->em->getRepository(BatchTwoOwner::class)->findBy(['id' => [1, 2, 3]], ['id' => 'ASC']);
        $four = $this->em->getRepository(BatchFourOwner::class)->findBy(['id' => [4, 5, 6, 7]], ['id' => 'ASC']);
        $this->statistics->reset();
        self::assertSame('target-1', $two[0]->target->name);
        self::assertFalse($this->em->isUninitializedObject($two[1]->target));
        self::assertTrue($this->em->isUninitializedObject($two[2]->target));
        foreach ($four as $owner) {
            self::assertTrue($this->em->isUninitializedObject($owner->target));
        }
        foreach ($four as $owner) {
            self::assertSame('target-' . $owner->id, $owner->target->name);
        }
        self::assertSame(2, $this->statistics->snapshot()->sqlCount);
    }

    public function testAttributeOptOutExcludesPeersDespiteGlobalBatching(): void
    {
        $default = $this->em->getRepository(BatchOwner::class)->findBy(['id' => [1, 2, 3]], ['id' => 'ASC']);
        $disabled = $this->em->getRepository(BatchDisabledOwner::class)->findBy(['id' => [4, 5, 6, 7]], ['id' => 'ASC']);
        $this->statistics->reset();
        self::assertSame('target-1', $default[0]->target->name);
        self::assertFalse($this->em->isUninitializedObject($default[2]->target));
        foreach ($disabled as $owner) {
            self::assertTrue($this->em->isUninitializedObject($owner->target));
        }
        self::assertSame('target-4', $disabled[0]->target->name);
        self::assertTrue($this->em->isUninitializedObject($disabled[1]->target));
        self::assertSame(2, $this->statistics->snapshot()->sqlCount);
    }

    public static function sharedReferenceOrders(): array { return [[true], [false]]; }

    #[DataProvider('sharedReferenceOrders')]
    public function testSharedReferenceUsesStrictestPolicyRegardlessOfHydrationOrder(bool $disabledFirst): void
    {
        $loadDisabled = fn () => $this->em->getRepository(BatchDisabledOwner::class)->findBy(['id' => 1])[0];
        $loadEnabled = fn () => $this->em->getRepository(BatchTwoOwner::class)->findBy([], ['id' => 'ASC']);
        if ($disabledFirst) {
            $disabled = $loadDisabled();
            $enabled = $loadEnabled();
        } else {
            $enabled = $loadEnabled();
            $disabled = $loadDisabled();
        }
        self::assertSame($disabled->target, $enabled[0]->target);
        $this->statistics->reset();
        self::assertSame('target-2', $enabled[1]->target->name);
        self::assertFalse($this->em->isUninitializedObject($enabled[2]->target));
        self::assertTrue($this->em->isUninitializedObject($disabled->target));
        self::assertSame('target-1', $disabled->target->name);
        self::assertTrue($this->em->isUninitializedObject($enabled[3]->target));
        self::assertSame(2, $this->statistics->snapshot()->sqlCount);
    }

    public function testBatchSizeDoesNotChangeEagerFetchSemantics(): void
    {
        $owners = $this->em->getRepository(BatchEagerOwner::class)->findBy([], ['id' => 'ASC']);
        $this->statistics->reset();
        foreach ($owners as $owner) {
            self::assertFalse($this->em->isUninitializedObject($owner->target));
            self::assertSame('target-' . $owner->id, $owner->target->name);
        }
        self::assertSame(0, $this->statistics->snapshot()->sqlCount);
    }

    public function testSqlErrorLeavesQueueRetryable(): void
    {
        $owners = $this->owners();
        $this->em->getConnection()->executeStatement('ALTER TABLE modernorm_test_batch_targets RENAME COLUMN name TO temporary_name');
        try {
            $owners[0]->target->name;
            self::fail('Expected missing column');
        } catch (\Doctrine\DBAL\Exception\InvalidFieldNameException) {
            self::assertTrue($this->em->isUninitializedObject($owners[0]->target));
        }
        $this->em->getConnection()->executeStatement('ALTER TABLE modernorm_test_batch_targets RENAME COLUMN temporary_name TO name');
        $this->statistics->reset();
        self::assertSame('target-1', $owners[0]->target->name);
        self::assertFalse($this->em->isUninitializedObject($owners[1]->target));
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
    }
    private function enableL2(): void
    {
        $config = clone $this->em->getConfiguration();
        $config->setSecondLevelCache(new \Symfony\Component\Cache\Adapter\ArrayAdapter());
        $this->em = new EntityManager($this->em->getConnection(), $config);
    }
}
