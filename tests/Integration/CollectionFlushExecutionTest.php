<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use ModernORM\EntityManager;
use ModernORM\Events;
use ModernORM\Exception\OptimisticLockException;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\PersistentCollection;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_cf_owners')]
class CollectionFlushOwner
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue] public ?int $id = null;
    #[ORM\Column] public string $name = 'original';
    /** @var Collection<int, CollectionFlushTarget> */
    #[ORM\ManyToMany(targetEntity: CollectionFlushTarget::class, cascade: ['persist'])]
    #[ORM\JoinTable(name: 'modernorm_test_cf_first', joinColumns: [new ORM\JoinColumn(name: 'owner_id')], inverseJoinColumns: [new ORM\JoinColumn(name: 'target_id')])]
    public Collection $first;
    /** @var Collection<int, CollectionFlushTarget> */
    #[ORM\ManyToMany(targetEntity: CollectionFlushTarget::class, cascade: ['persist'])]
    #[ORM\JoinTable(name: 'modernorm_test_cf_second', joinColumns: [new ORM\JoinColumn(name: 'owner_id')], inverseJoinColumns: [new ORM\JoinColumn(name: 'target_id')])]
    public Collection $second;
    public function __construct()
    {
        $this->first = new ArrayCollection();
        $this->second = new ArrayCollection();
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_cf_targets')]
class CollectionFlushTarget
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue] public ?int $id = null;
    public function __construct(#[ORM\Column] public string $name)
    {
    }
}

final class CollectionFlushExecutionTest extends TestCase
{
    private EntityManager $em;
    protected function setUp(): void
    {
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ]), ORMSetup::createAttributeMetadataConfig([__DIR__], true, cache: new ArrayAdapter()));
        $this->em->getConnection()->executeStatement('DROP TABLE IF EXISTS modernorm_test_cf_blocker CASCADE');
        $metadata = array_map($this->em->getClassMetadata(...), [CollectionFlushOwner::class, CollectionFlushTarget::class]);
        $tool = new SchemaTool($this->em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }
    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DROP TABLE IF EXISTS modernorm_test_cf_blocker, modernorm_test_cf_first, modernorm_test_cf_second, modernorm_test_cf_targets, modernorm_test_cf_owners CASCADE');
        $this->em->getConnection()->close();
    }
    private function seed(): CollectionFlushOwner
    {
        $owner = new CollectionFlushOwner();
        $owner->first->add(new CollectionFlushTarget('first-original'));
        $owner->second->add(new CollectionFlushTarget('second-original'));
        $this->em->persist($owner);
        $this->em->flush();
        return $owner;
    }
    /** @return array{PersistentCollection, PersistentCollection} */
    private function collections(CollectionFlushOwner $owner): array
    {
        self::assertInstanceOf(PersistentCollection::class, $owner->first);
        self::assertInstanceOf(PersistentCollection::class, $owner->second);
        return [$owner->first, $owner->second];
    }
    private function countRows(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_cf_' . $table);
    }
    public function testUpdatesFollowEntityWritesAndSnapshotsAdvanceOnlyOnSuccess(): void
    {
        $owner = $this->seed();
        [$first, $second] = $this->collections($owner);
        $before = $first->getSnapshot();
        $new = new CollectionFlushTarget('new');
        $first->add($new);
        $second->add($new);
        $owner->name = 'updated';
        $listener = new class ($first, $before, $new) {
            public int $calls = 0;
            public function __construct(private PersistentCollection $collection, private array $snapshot, private CollectionFlushTarget $new)
            {
            }
            public function postUpdate(\ModernORM\Event\PostUpdateEventArgs $args): void
            {
                ++$this->calls;
                TestCase::assertNotNull($this->new->id);
                TestCase::assertSame($this->snapshot, $this->collection->getSnapshot());
                TestCase::assertTrue($this->collection->isDirty());
            }
        };
        $this->em->getEventManager()->addEventListener([Events::postUpdate], $listener);
        $this->em->flush();
        self::assertSame(1, $listener->calls);
        self::assertSame(2, $this->countRows('first'));
        self::assertSame(2, $this->countRows('second'));
        foreach ([$first, $second] as $collection) {
            self::assertSame($collection->toArray(), $collection->getSnapshot());
            self::assertFalse($collection->isDirty());
        }
    }
    public function testSecondCollectionUpdateFailureRollsBackAllWritesWithoutNewSnapshots(): void
    {
        $owner = $this->seed();
        [$first, $second] = $this->collections($owner);
        $oldTarget = $second->first();
        $this->em->getConnection()->executeStatement('ALTER TABLE modernorm_test_cf_second ADD CONSTRAINT reject_new_link CHECK (target_id = ' . $oldTarget->id . ')');
        $first->add(new CollectionFlushTarget('new-first'));
        $second->add(new CollectionFlushTarget('blocked'));
        $owner->name = 'attempted';
        $snapshots = [$first->getSnapshot(), $second->getSnapshot()];
        try {
            $this->em->flush();
            self::fail('The second collection update must fail.');
        } catch (DriverException $error) {
            self::assertSame('23514', $error->getSQLState());
            self::assertSame(1, $this->countRows('first'));
            self::assertSame(1, $this->countRows('second'));
            self::assertSame(2, $this->countRows('targets'));
            self::assertSame('original', $this->em->getConnection()->fetchOne('SELECT name FROM modernorm_test_cf_owners'));
            self::assertSame($snapshots, [$first->getSnapshot(), $second->getSnapshot()]);
            self::assertTrue($first->isDirty());
            self::assertTrue($second->isDirty());
            self::assertFalse($this->em->isOpen());
        }
    }
    public function testSecondCollectionDeletionFailureRollsBackFirstDeletion(): void
    {
        $owner = $this->seed();
        [$first, $second] = $this->collections($owner);
        $this->em->getConnection()->executeStatement('CREATE TABLE modernorm_test_cf_blocker (owner_id INT, target_id INT, FOREIGN KEY (owner_id, target_id) REFERENCES modernorm_test_cf_second (owner_id, target_id))');
        $this->em->getConnection()->executeStatement('INSERT INTO modernorm_test_cf_blocker SELECT owner_id, target_id FROM modernorm_test_cf_second');
        $first->clear();
        $second->clear();
        // clear() already takes an empty snapshot; failed flush must preserve that pre-flush state.
        $snapshots = [$first->getSnapshot(), $second->getSnapshot()];
        try {
            $this->em->flush();
            self::fail('The second collection deletion must fail.');
        } catch (ForeignKeyConstraintViolationException) {
            self::assertSame(1, $this->countRows('first'));
            self::assertSame(1, $this->countRows('second'));
            self::assertSame($snapshots, [$first->getSnapshot(), $second->getSnapshot()]);
            self::assertFalse($this->em->isOpen());
        }
    }
    public function testFailedCommitKeepsBothCollectionsSnapshotsUnchanged(): void
    {
        $owner = $this->seed();
        [$first, $second] = $this->collections($owner);
        $db = $this->em->getConnection();
        $constraint = $db->fetchOne("SELECT conname FROM pg_constraint WHERE conrelid = 'modernorm_test_cf_second'::regclass AND confrelid = 'modernorm_test_cf_targets'::regclass AND contype = 'f'");
        self::assertIsString($constraint);
        $db->executeStatement('ALTER TABLE modernorm_test_cf_second ALTER CONSTRAINT ' . $db->quoteIdentifier($constraint) . ' DEFERRABLE INITIALLY DEFERRED');
        $first->add(new CollectionFlushTarget('new-first'));
        $second->add($this->em->getReference(CollectionFlushTarget::class, 999999));
        $snapshots = [$first->getSnapshot(), $second->getSnapshot()];
        try {
            $this->em->flush();
            self::fail('Deferred FK violation must fail COMMIT.');
        } catch (OptimisticLockException) {
            self::assertSame(1, $this->countRows('first'));
            self::assertSame(1, $this->countRows('second'));
            self::assertSame(2, $this->countRows('targets'));
            self::assertSame($snapshots, [$first->getSnapshot(), $second->getSnapshot()]);
            self::assertTrue($first->isDirty());
            self::assertTrue($second->isDirty());
            self::assertFalse($this->em->isOpen());
        }
    }
    public static function explicitOwners(): array
    {
        return ['untracked owner' => [false], 'explicitly persisted owner' => [true]];
    }
    #[DataProvider('explicitOwners')]
    public function testDeferredExplicitDeletionRequiresPersistedOwner(bool $persist): void
    {
        $owner = $this->seed();
        $this->em->getClassMetadata(CollectionFlushOwner::class)->setChangeTrackingPolicy(ORM\ClassMetadata::CHANGETRACKING_DEFERRED_EXPLICIT);
        $owner->first->clear();
        if ($persist) {
            $this->em->persist($owner);
        }
        $this->em->flush();
        self::assertSame($persist ? 0 : 1, $this->countRows('first'));
        self::assertSame(1, $this->countRows('second'));
    }
}
