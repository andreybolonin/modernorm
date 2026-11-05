<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_collection_batch_owners')]
final class CollectionBatchOwner
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column] public string $name;
    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: CollectionBatchItem::class, cascade: ['persist'], orphanRemoval: true, indexBy: 'code', batchSize: 3)]
    #[ORM\OrderBy(['rank' => 'ASC', 'id' => 'ASC'])]
    public Collection $items;
    #[ORM\OneToMany(mappedBy: 'reviewer', targetEntity: CollectionBatchItem::class, indexBy: 'code', batchSize: 3)]
    public Collection $reviews;
    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: CollectionBatchItem::class, fetch: 'EXTRA_LAZY', indexBy: 'code', batchSize: 3)]
    #[ORM\OrderBy(['rank' => 'ASC'])]
    public Collection $extraItems;
    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: CollectionBatchItem::class, batchSize: 1)]
    public Collection $disabledItems;
    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: CollectionBatchItem::class)]
    public Collection $defaultItems;
    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: CollectionBatchItem::class, indexBy: 'id', batchSize: 3)]
    #[ORM\OrderBy(['rank' => 'ASC'])] public Collection $idItems;
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_collection_batch_items')]
final class CollectionBatchItem
{
    public function __construct(
        #[ORM\Id, ORM\Column] public int $id,
        #[ORM\ManyToOne(targetEntity: CollectionBatchOwner::class, inversedBy: 'items')]
        #[ORM\JoinColumn(nullable: false)] public CollectionBatchOwner $owner,
        #[ORM\Column(unique: true)] public string $code,
        #[ORM\Column] public int $rank,
        #[ORM\Column] public string $name = 'original',
        #[ORM\ManyToOne(targetEntity: CollectionBatchOwner::class, inversedBy: 'reviews')] public ?CollectionBatchOwner $reviewer = null,
    ) {
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_collection_batch_text_owners')]
final class CollectionBatchTextOwner
{
    #[ORM\Id, ORM\Column(length: 32)] public string $code;
    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: CollectionBatchTextItem::class, batchSize: 2)]
    #[ORM\OrderBy(['id' => 'DESC'])] public Collection $items;
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_collection_batch_text_items')]
final class CollectionBatchTextItem
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\ManyToOne(targetEntity: CollectionBatchTextOwner::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'owner_code', referencedColumnName: 'code', nullable: false)] public CollectionBatchTextOwner $owner;
}

final class CollectionBatchRecorder extends AbstractLogger
{
    public array $sql = [];
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql'])) {
            $this->sql[] = $context['sql'];
        }
    }
}

final class CollectionBatchFetchingTest extends TestCase
{
    private EntityManager $em;
    private Statistics $statistics;
    private CollectionBatchRecorder $recorder;

    protected function setUp(): void
    {
        $this->statistics = new Statistics(enabled: true);
        $this->recorder = new CollectionBatchRecorder();
        $dbal = new DBALConfiguration();
        $dbal->setMiddlewares([new Middleware($this->statistics), new LoggingMiddleware($this->recorder)]);
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $dbal), $config);
        $metadata = array_map($this->em->getClassMetadata(...), [CollectionBatchOwner::class, CollectionBatchItem::class, CollectionBatchTextOwner::class, CollectionBatchTextItem::class]);
        $schema = new SchemaTool($this->em);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        foreach (['01', '1', 'a b'] as $code) {
            $this->em->getConnection()->insert('modernorm_test_collection_batch_text_owners', ['code' => $code]);
        }
        foreach ([[101, '01'], [102, '01'], [103, '1']] as [$id, $code]) {
            $this->em->getConnection()->insert('modernorm_test_collection_batch_text_items', ['id' => $id, 'owner_code' => $code]);
        }
        for ($id = 1; $id <= 7; ++$id) {
            $this->em->getConnection()->insert('modernorm_test_collection_batch_owners', ['id' => $id, 'name' => 'owner-' . $id]);
        }
        foreach ([[11, 1, 'b', 20, null], [12, 1, 'a', 10, 2], [21, 2, 'c', 5, null], [41, 4, 'd', 10, 1], [61, 6, 'e', 10, null], [71, 7, 'f', 10, null]] as [$id, $owner, $code, $rank, $reviewer]) {
            $this->em->getConnection()->insert('modernorm_test_collection_batch_items', ['id' => $id, 'owner_id' => $owner, 'code' => $code, 'rank' => $rank, 'name' => 'original', 'reviewer_id' => $reviewer]);
        }
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DROP TABLE IF EXISTS modernorm_test_collection_batch_items, modernorm_test_collection_batch_owners, modernorm_test_collection_batch_text_items, modernorm_test_collection_batch_text_owners CASCADE');
        $this->em->getConnection()->close();
    }

    private function owners(): array
    {
        return $this->em->getRepository(CollectionBatchOwner::class)->findBy([], ['id' => 'ASC']);
    }

    private function reset(): void
    {
        $this->statistics->reset();
        $this->recorder->sql = [];
    }

    public function testSevenCollectionsIncludingEmptyOwnersUseThreeSelects(): void
    {
        $owners = $this->owners();
        $this->reset();
        self::assertSame(['a', 'b'], $owners[0]->items->getKeys());
        self::assertSame(['c'], $owners[1]->items->getKeys());
        self::assertTrue($owners[2]->items->isEmpty());
        self::assertTrue($owners[2]->items->isInitialized());
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        self::assertStringContainsString(' IN (', $this->recorder->sql[0]);
        foreach ($owners as $owner) {
            $owner->items->toArray();
        }
        self::assertSame(3, $this->statistics->snapshot()->sqlCount);
        self::assertSame(['d'], $owners[3]->items->getKeys());
        self::assertTrue($owners[4]->items->isEmpty());
        self::assertSame(['f'], $owners[6]->items->getKeys());
    }

    public function testTriggerCanBeInMiddleAndRolesDoNotMix(): void
    {
        $owners = $this->owners();
        $this->reset();
        self::assertSame(['e'], $owners[5]->items->getKeys());
        self::assertTrue($owners[0]->items->isInitialized());
        self::assertTrue($owners[1]->items->isInitialized());
        self::assertFalse($owners[2]->items->isInitialized());
        foreach ($owners as $owner) {
            self::assertFalse($owner->reviews->isInitialized());
        }
        self::assertSame(['d'], $owners[0]->reviews->getKeys());
        self::assertSame(['a'], $owners[1]->reviews->getKeys());
        self::assertSame(2, $this->statistics->snapshot()->sqlCount);
    }

    public function testIdentityMapAndLocalChildChangesSurviveBatchHydration(): void
    {
        $owners = $this->owners();
        $item = $this->em->find(CollectionBatchItem::class, 12);
        $item->name = 'local';
        $this->reset();
        self::assertSame($item, $owners[0]->items->get('a'));
        self::assertSame('local', $item->name);
        self::assertSame($owners[0], $item->owner);
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        $this->em->flush();
        self::assertSame('local', $this->em->getConnection()->fetchOne('SELECT name FROM modernorm_test_collection_batch_items WHERE id = 12'));
    }

    public function testPendingAdditionsOnTriggerAndPeerKeepDirtySnapshots(): void
    {
        $owners = $this->owners();
        $first = new CollectionBatchItem(100, $owners[0], 'pending-first', 1);
        $peer = new CollectionBatchItem(101, $owners[1], 'pending-peer', 1);
        $owners[0]->items->add($first);
        $owners[1]->items->add($peer);
        self::assertFalse($owners[0]->items->isInitialized());
        $this->reset();
        self::assertCount(3, $owners[0]->items);
        self::assertTrue($owners[1]->items->contains($peer));
        self::assertTrue($owners[0]->items->isDirty());
        self::assertTrue($owners[1]->items->isDirty());
        self::assertNotContains($first, $owners[0]->items->getSnapshot());
        self::assertNotContains($peer, $owners[1]->items->getSnapshot());
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        $this->em->flush();
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_collection_batch_items WHERE id IN (100,101)'));
    }

    public function testChangingOwningFkBeforeInitializationIsNotOverwritten(): void
    {
        $owners = $this->owners();
        $item = $this->em->find(CollectionBatchItem::class, 12);
        $item->owner = $owners[1];
        $owners[1]->items->add($item);
        $this->reset();
        self::assertSame(['b'], $owners[0]->items->getKeys());
        self::assertTrue($owners[1]->items->contains($item));
        self::assertSame($owners[1], $item->owner);
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        $this->em->flush();
        self::assertSame(2, $this->em->getConnection()->fetchOne('SELECT owner_id FROM modernorm_test_collection_batch_items WHERE id = 12'));
    }

    public function testRemovalDoesNotGetUndoneByLaterBatchAndOrphanDeleteStillWorks(): void
    {
        $owners = $this->owners();
        $item = $owners[0]->items->get('a');
        $owners[0]->items->removeElement($item);
        $this->reset();
        $owners[3]->items->toArray();
        self::assertFalse($owners[0]->items->contains($item));
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        $this->em->flush();
        self::assertFalse($this->em->getConnection()->fetchOne('SELECT id FROM modernorm_test_collection_batch_items WHERE id = 12'));
    }

    public function testExtraLazyCountContainsAndSliceDoNotInitializePeers(): void
    {
        $owners = $this->owners();
        $this->reset();
        self::assertCount(2, $owners[0]->extraItems);
        self::assertTrue($owners[0]->extraItems->containsKey('a'));
        self::assertCount(1, $owners[0]->extraItems->slice(0, 1));
        foreach ($owners as $owner) {
            self::assertFalse($owner->extraItems->isInitialized());
        }
        $this->reset();
        self::assertSame(['a', 'b'], $owners[0]->extraItems->getKeys());
        self::assertTrue($owners[1]->extraItems->isInitialized());
        self::assertTrue($owners[2]->extraItems->isInitialized());
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
    }

    public function testGlobalFallbackAndAssociationOptOutAreIndependent(): void
    {
        $this->em->getConfiguration()->setLazyFetchBatchSize(2);
        $owners = $this->owners();
        $this->reset();
        $owners[0]->defaultItems->toArray();
        self::assertTrue($owners[1]->defaultItems->isInitialized());
        self::assertFalse($owners[2]->defaultItems->isInitialized());
        $owners[0]->disabledItems->toArray();
        self::assertFalse($owners[1]->disabledItems->isInitialized());
        self::assertSame(2, $this->statistics->snapshot()->sqlCount);
    }

    public static function lifecycleActions(): array
    {
        return [['clear', false], ['close', false], ['detach', false], ['clear', true], ['close', true], ['detach', true]];
    }

    #[DataProvider('lifecycleActions')]
    public function testLifecycleExcludesOwnersFromBatch(string $action, bool $l2): void
    {
        if ($l2) {
            $this->enableL2();
        }
        $owners = $this->owners();
        if ($action === 'detach') {
            $this->em->detach($owners[0]);
            $this->reset();
            $owners[1]->items->toArray();
            self::assertFalse($owners[0]->items->isInitialized());
            self::assertTrue($owners[3]->items->isInitialized());
        } else {
            $this->em->$action();
            $this->reset();
            $owners[0]->items->toArray();
            self::assertFalse($owners[1]->items->isInitialized());
        }
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
    }

    public function testSqlFailureCanBeRetriedWithoutLosingPendingItems(): void
    {
        $owners = $this->owners();
        $pending = new CollectionBatchItem(100, $owners[0], 'pending', 1);
        $owners[0]->items->add($pending);
        $this->em->getConnection()->executeStatement('ALTER TABLE modernorm_test_collection_batch_items RENAME COLUMN rank TO temporary_rank');
        try {
            $owners[0]->items->toArray();
            self::fail('Expected invalid column');
        } catch (\Doctrine\DBAL\Exception) {
            self::assertFalse($owners[0]->items->isInitialized());
            self::assertTrue($owners[0]->items->unwrap()->contains($pending));
            self::assertTrue($owners[0]->items->isDirty());
        }
        $this->em->getConnection()->executeStatement('ALTER TABLE modernorm_test_collection_batch_items RENAME COLUMN temporary_rank TO rank');
        $this->reset();
        self::assertCount(3, $owners[0]->items);
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
    }

    public function testAnotherManagerAndInitializedCollectionsAreNotTouched(): void
    {
        $owners = $this->owners();
        $other = new EntityManager($this->em->getConnection(), $this->em->getConfiguration());
        $otherOwners = $other->getRepository(CollectionBatchOwner::class)->findBy([], ['id' => 'ASC']);
        $this->reset();
        $otherOwners[0]->items->toArray();
        self::assertFalse($owners[0]->items->isInitialized());
        self::assertTrue($otherOwners[1]->items->isInitialized());
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
    }

    public function testStringOwnerIdentifiersAndUnindexedCollectionsKeepTheirOrder(): void
    {
        $owners = [];
        foreach (['01', '1', 'a b'] as $code) {
            $owners[] = $this->em->find(CollectionBatchTextOwner::class, $code);
        }
        $this->reset();
        self::assertSame([102, 101], array_map(static fn ($item) => $item->id, $owners[0]->items->toArray()));
        self::assertSame([0, 1], $owners[0]->items->getKeys());
        self::assertSame([103], array_map(static fn ($item) => $item->id, $owners[1]->items->toArray()));
        self::assertSame($owners[0], $owners[0]->items->first()->owner);
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        self::assertTrue($owners[2]->items->isEmpty());
        self::assertSame(2, $this->statistics->snapshot()->sqlCount);
    }

    public function testBatchWithNoRowsInitializesEverySelectedCollection(): void
    {
        $owners = $this->em->getRepository(CollectionBatchOwner::class)->findBy(['id' => [3, 5]], ['id' => 'ASC']);
        $this->reset();
        self::assertTrue($owners[0]->items->isEmpty());
        self::assertTrue($owners[1]->items->isInitialized());
        self::assertSame([], $owners[1]->items->getSnapshot());
        self::assertFalse($owners[0]->items->isDirty());
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
    }

    public function testReplacedAndInitializedPeerCollectionsAreExcluded(): void
    {
        $owners = $this->owners();
        $old = $owners[0]->items;
        $owners[0]->items = new ArrayCollection();
        $this->reset();
        $owners[1]->items->toArray();
        self::assertFalse($old->isInitialized());
        self::assertTrue($owners[0]->items->isEmpty());
        self::assertTrue($owners[3]->items->isInitialized());
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
    }

    public function testPendingDeleteIsNotReintroducedByBatchLoading(): void
    {
        $owners = $this->owners();
        $item = $this->em->find(CollectionBatchItem::class, 12);
        $this->em->remove($item);
        $this->reset();
        self::assertSame(['b'], $owners[0]->items->getKeys());
        self::assertTrue($this->em->getUnitOfWork()->isScheduledForDelete($item));
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        $this->em->flush();
        self::assertFalse($this->em->getConnection()->fetchOne('SELECT id FROM modernorm_test_collection_batch_items WHERE id = 12'));
    }

    public function testL2EnabledManagerBatchesUncachedCollections(): void
    {
        $config = clone $this->em->getConfiguration();
        $config->setSecondLevelCache(new \Symfony\Component\Cache\Adapter\ArrayAdapter());
        $other = new EntityManager($this->em->getConnection(), $config);
        $owners = $other->getRepository(CollectionBatchOwner::class)->findBy([], ['id' => 'ASC']);
        $this->reset();
        self::assertSame(['a', 'b'], $owners[0]->items->getKeys());
        self::assertTrue($owners[1]->items->isInitialized());
        self::assertTrue($owners[2]->items->isEmpty());
        self::assertSame($owners[0], $owners[0]->items->first()->owner);
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        self::assertStringContainsString(' IN (', $this->recorder->sql[0]);
        foreach ($owners as $owner) {
            $owner->items->toArray();
        }
        self::assertSame(3, $this->statistics->snapshot()->sqlCount);
    }

    public function testIntegerIndexByUsesMappedKeys(): void
    {
        $owners = $this->owners();
        $this->reset();
        self::assertSame([12, 11], $owners[0]->idItems->getKeys());
        self::assertSame([21], $owners[1]->idItems->getKeys());
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
    }

    public function testEagerFetchModeKeepsItsOwnLoadingStrategy(): void
    {
        $query = $this->em->createQueryBuilder()->fromEntity(CollectionBatchOwner::class, 'o')->orderBy('o.id')->getQuery();
        $query->setFetchMode(CollectionBatchOwner::class, 'items', ORM\ClassMetadata::FETCH_EAGER);
        $owners = $query->getResult();
        $this->reset();
        foreach ($owners as $owner) {
            self::assertTrue($owner->items->isInitialized());
            $owner->items->toArray();
        }
        self::assertSame(0, $this->statistics->snapshot()->sqlCount);
    }

    public function testPostLoadCanInitializeASelectedCollectionWithoutOverwritingIt(): void
    {
        $owners = $this->owners();
        $this->em->getEventManager()->addEventListener([\ModernORM\Events::postLoad], new class () {
            private bool $done = false;
            public function postLoad(\ModernORM\Event\PostLoadEventArgs $event): void
            {
                $entity = $event->getObject();
                if (! $this->done && $entity instanceof CollectionBatchItem) {
                    $this->done = true;
                    $entity->owner->items->toArray();
                }
            }
        });
        $this->reset();
        self::assertSame(['a', 'b'], $owners[0]->items->getKeys());
        self::assertSame(['c'], $owners[1]->items->getKeys());
        self::assertTrue($owners[2]->items->isInitialized());
        self::assertFalse($owners[0]->items->isDirty());
        self::assertSame(2, $this->statistics->snapshot()->sqlCount);
    }

    public function testImmutableOwnerStillAllowsCollectionBatchingAndChildPersistence(): void
    {
        $this->em->getClassMetadata(CollectionBatchOwner::class)->markReadOnly();
        $owners = $this->owners();
        $this->reset();
        self::assertSame(['a', 'b'], $owners[0]->items->getKeys());
        $owners[1]->items->add(new CollectionBatchItem(100, $owners[1], 'new', 1));
        self::assertTrue($owners[1]->items->isInitialized());
        $this->em->flush();
        self::assertSame(2, $this->em->getConnection()->fetchOne('SELECT owner_id FROM modernorm_test_collection_batch_items WHERE id = 100'));
    }
    private function enableL2(): void
    {
        $config = clone $this->em->getConfiguration();
        $config->setSecondLevelCache(new \Symfony\Component\Cache\Adapter\ArrayAdapter());
        $this->em = new EntityManager($this->em->getConnection(), $config);
    }
}
