<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use ModernORM\Behavior\SoftDeleteTrait;
use ModernORM\Cache\CacheWritePolicy;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Events;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\SoftDelete, ORM\Table(name: 'modernorm_test_sd_items')]
#[ORM\UniqueConstraint(name: 'modernorm_test_sd_active_name', columns: ['name'], options: ['where' => 'deleted_at IS NULL'])]
class SoftItem
{
    use SoftDeleteTrait;

    #[ORM\Id, ORM\Column, ORM\GeneratedValue] public ?int $id = null;
    #[ORM\Column] public string $name = 'item';
    #[ORM\ManyToOne(targetEntity: SoftOwner::class, inversedBy: 'items')]
    public ?SoftOwner $owner = null;
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_sd_owners')]
class SoftOwner
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue] public ?int $id = null;
    #[ORM\Column] public string $name = 'owner';
    #[ORM\OneToMany(targetEntity: SoftItem::class, mappedBy: 'owner', batchSize: 4)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $items;
    #[ORM\ManyToOne(targetEntity: SoftItem::class)]
    #[ORM\JoinColumn(name: 'favorite_id', nullable: true)]
    public ?SoftItem $favorite = null;
    #[ORM\ManyToMany(targetEntity: SoftItem::class, batchSize: 4)]
    #[ORM\JoinTable(name: 'modernorm_test_sd_owner_tags')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $tags;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->tags = new ArrayCollection();
    }
}

#[ORM\Entity, ORM\SoftDelete, ORM\Table(name: 'modernorm_test_sd_cached')]
#[ORM\Cacheable(writePolicy: CacheWritePolicy::INVALIDATE)]
class SoftCachedItem
{
    use SoftDeleteTrait;

    #[ORM\Id, ORM\Column, ORM\GeneratedValue] public ?int $id = null;
    #[ORM\Column(unique: true)] public string $name = 'cached';
    #[ORM\Version, ORM\Column(type: 'integer')] public int $version = 1;
}

final class SoftDeleteTest extends TestCase
{
    private EntityManager $em;
    private ArrayAdapter $cache;

    private function manager(): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__]));
        $config->setMetadataCache(new ArrayAdapter(storeSerialized: true));
        $config->setSecondLevelCache($this->cache, 'softdelete-test');
        return new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ]), $config);
    }

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter(storeSerialized: true);
        $this->em = $this->manager();
        $metadata = array_map($this->em->getClassMetadata(...), [SoftItem::class, SoftOwner::class, SoftCachedItem::class]);
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
        $connection->executeStatement('DROP TABLE IF EXISTS modernorm_test_sd_owner_tags, modernorm_test_sd_items, modernorm_test_sd_owners, modernorm_test_sd_cached CASCADE');
        $connection->close();
    }

    public function testRemoveRestoreAndExplicitHardDelete(): void
    {
        $item = new SoftItem();
        $this->em->persist($item);
        $this->em->flush();
        $id = $item->id;
        $this->em->remove($item);
        $deletedAt = $item->getDeletedAt();
        $this->em->remove($item);
        self::assertSame($deletedAt, $item->getDeletedAt());
        $this->em->flush();
        self::assertTrue($item->isDeleted());
        self::assertSame($id, $item->id);
        self::assertNull($this->em->find(SoftItem::class, $id));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_sd_items'));
        $this->em->clear();
        self::assertNull($this->em->getReference(SoftItem::class, $id));
        self::assertSame(0, $this->em->getRepository(SoftItem::class)->count([]));
        self::assertNull($this->em->getRepository(SoftItem::class)->findOneBy(['id' => $id]));
        self::assertSame([], $this->em->getRepository(SoftItem::class)->findAll());
        $deleted = $this->em->findIncludingDeleted(SoftItem::class, $id);
        self::assertTrue($deleted->isDeleted());
        $this->em->restore($deleted);
        $this->em->flush();
        self::assertSame($deleted, $this->em->find(SoftItem::class, $id));
        self::assertFalse($deleted->isDeleted());
        $this->em->hardDelete($deleted);
        $this->em->flush();
        $this->em->clear();
        self::assertNull($this->em->findIncludingDeleted(SoftItem::class, $id));
    }

    public function testQueryScopeSurvivesWhereAndPaginationAndIncludesDeletedExplicitly(): void
    {
        foreach (['a', 'b', 'c'] as $name) {
            $item = new SoftItem();
            $item->name = $name;
            $this->em->persist($item);
            if ($name === 'a') {
                $deleted = $item;
            }
        }
        $this->em->flush();
        $this->em->remove($deleted);
        $this->em->flush();
        $this->em->clear();
        $query = $this->em->createQueryBuilder()->fromEntity(SoftItem::class, 's')->where('1 = 1')->orWhere('1 = 1')->orderBy('s.id', 'ASC')->setMaxResults(1);
        self::assertSame('b', $query->getQuery()->getSingleResult()->name);
        $query = $this->em->createQueryBuilder()->withDeleted()->fromEntity(SoftItem::class, 's')->orderBy('s.id', 'ASC');
        self::assertCount(3, $query->getQuery()->getResult());
    }

    public function testNullableAssociationsAndBothCollectionBatchLoadersHideDeletedTargets(): void
    {
        $owners = [];
        for ($i = 0; $i < 3; ++$i) {
            $owner = new SoftOwner();
            $owner->name = 'owner'.$i;
            $this->em->persist($owner);
            $owners[] = $owner;
            foreach (['active', 'deleted'] as $name) {
                $item = new SoftItem();
                $item->name = $name.$i;
                $item->owner = $owner;
                $this->em->persist($item);
                $owner->tags->add($item);
                if ($name === 'deleted') {
                    $owner->favorite = $item;
                    $deleted[] = $item;
                }
            }
        }
        $this->em->flush();
        foreach ($deleted as $item) {
            $this->em->remove($item);
        }
        $this->em->flush();
        $this->em->clear();
        $loaded = $this->em->getRepository(SoftOwner::class)->findBy([], ['id' => 'ASC']);
        foreach ($loaded as $index => $owner) {
            self::assertNull($owner->favorite);
            self::assertCount(1, $owner->items);
            self::assertCount(1, $owner->tags);
            self::assertSame('active'.$index, $owner->items->first()->name);
            self::assertSame($owner->items->first(), $owner->tags->first());
        }
        $this->em->clear();
        $loaded = $this->em->createQueryBuilder()->fromEntity(SoftOwner::class, 'o')->orderBy('o.id', 'ASC')->getQuery()->getResult();
        self::assertCount(3, $loaded);
        foreach ($loaded as $owner) {
            self::assertNull($owner->favorite);
        }
    }

    public function testL2InvalidationAndRollbackDuringSoftDelete(): void
    {
        $item = new SoftCachedItem();
        $this->em->persist($item);
        $this->em->flush();
        $id = $item->id;
        $this->em->clear();
        $item = $this->em->find(SoftCachedItem::class, $id);
        self::assertTrue($this->em->getCache()->contains(SoftCachedItem::class, $id));
        $listener = new class () {
            public function postUpdate(): void
            {
                throw new \RuntimeException('rollback soft delete');
            }
        };
        $this->em->getEventManager()->addEventListener([Events::postUpdate], $listener);
        $this->em->remove($item);
        try {
            $this->em->flush();
            self::fail('Expected rollback');
        } catch (\RuntimeException $error) {
            self::assertSame('rollback soft delete', $error->getMessage());
        }
        self::assertFalse($this->em->isOpen());
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        $this->em->getConnection()->close();
        $this->em = $this->manager();
        $item = $this->em->find(SoftCachedItem::class, $id);
        self::assertNotNull($item);
        self::assertFalse($item->isDeleted());
        self::assertSame(1, $item->version);
        $this->em->remove($item);
        $this->em->flush();
        $this->em->clear();
        self::assertFalse($this->em->getCache()->contains(SoftCachedItem::class, $id));
        self::assertNull($this->em->find(SoftCachedItem::class, $id));
        $item = $this->em->findIncludingDeleted(SoftCachedItem::class, $id);
        self::assertTrue($item->isDeleted());
        self::assertSame(2, $item->version);
        self::assertFalse($this->em->getCache()->contains(SoftCachedItem::class, $id));
        $this->em->restore($item);
        $this->em->flush();
        $this->em->clear();
        self::assertFalse($this->em->find(SoftCachedItem::class, $id)->isDeleted());
        self::assertSame(3, $this->em->find(SoftCachedItem::class, $id)->version);
        self::assertTrue($this->em->getCache()->contains(SoftCachedItem::class, $id));
    }

    public function testPartialUniqueIndexPermitsReuseButRestoreCanConflict(): void
    {
        $first = new SoftItem();
        $this->em->persist($first);
        $this->em->flush();
        $this->em->remove($first);
        $this->em->flush();
        $second = new SoftItem();
        $this->em->persist($second);
        $this->em->flush();
        $this->em->restore($first);
        try {
            $this->em->flush();
            self::fail('Expected restore conflict');
        } catch (UniqueConstraintViolationException) {
        }
        self::assertFalse($this->em->isOpen());
        self::assertFalse($this->em->contains($first));
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        // Rollback does not rewind the PHP object changed by restore().
        self::assertFalse($first->isDeleted());
        $this->em->getConnection()->close();
        $this->em = $this->manager();
        self::assertNull($this->em->find(SoftItem::class, $first->id));
        self::assertNotNull($this->em->find(SoftItem::class, $second->id));
        $deleted = $this->em->findIncludingDeleted(SoftItem::class, $first->id);
        self::assertTrue($deleted->isDeleted());
        // Resolve the conflict explicitly; retry with freshly loaded state.
        $deleted->name = 'restored-item';
        $this->em->restore($deleted);
        $this->em->flush();
        $this->em->clear();
        self::assertSame('restored-item', $this->em->find(SoftItem::class, $first->id)->name);
        self::assertSame('item', $this->em->find(SoftItem::class, $second->id)->name);
    }

    public function testBulkDeleteRemainsPhysicalAndInvalidatesL2(): void
    {
        $item = new SoftCachedItem();
        $this->em->persist($item);
        $this->em->flush();
        $id = $item->id;
        $this->em->clear();
        $this->em->find(SoftCachedItem::class, $id);
        self::assertTrue($this->em->getCache()->contains(SoftCachedItem::class, $id));
        $this->em->bulk()->delete(SoftCachedItem::class, 'id = :id', \ModernORM\Bulk\BulkPolicy::CLEAR, ['id' => $id]);
        self::assertNull($this->em->findIncludingDeleted(SoftCachedItem::class, $id));
        self::assertFalse($this->em->getCache()->contains(SoftCachedItem::class, $id));
    }

    public function testExplicitCollectionJoinIsFilteredWithoutDroppingItsOwner(): void
    {
        $owner = new SoftOwner();
        $this->em->persist($owner);
        $item = new SoftItem();
        $item->owner = $owner;
        $this->em->persist($item);
        $this->em->flush();
        $this->em->remove($item);
        $this->em->flush();
        $this->em->clear();
        $query = $this->em->createQueryBuilder()->fromEntity(SoftOwner::class, 'o');
        $query->addJoinedEntity(SoftItem::class, 's', 'o', 'items')->leftJoin('o', 'modernorm_test_sd_items', 's', 's.owner_id = o.id');
        $loaded = $query->getQuery()->getResult();
        self::assertCount(1, $loaded);
        self::assertCount(0, $loaded[0]->items);
    }

    public function testReadOnlyManagedEntityCannotSilentlyIgnoreSoftDelete(): void
    {
        $item = new SoftItem();
        $this->em->persist($item);
        $this->em->flush();
        $this->em->getUnitOfWork()->markReadOnly($item);
        try {
            $this->em->remove($item);
            self::fail('Expected explicit read-only rejection');
        } catch (\LogicException $error) {
            self::assertStringContainsString('read-only', $error->getMessage());
        }
        self::assertFalse($item->isDeleted());
        self::assertSame(1, $this->em->getRepository(SoftItem::class)->count([]));
    }

    public function testRemovingUnflushedInsertCancelsInsertion(): void
    {
        $item = new SoftItem();
        $this->em->persist($item);
        $this->em->remove($item);
        $this->em->flush();
        self::assertSame(0, $this->em->getRepository(SoftItem::class)->count([]));
        self::assertNull($item->getDeletedAt());
    }

    public function testOnlyDeletedScopeSurvivesWherePaginationAndRestore(): void
    {
        $items = [];
        foreach (['active', 'trash-a', 'trash-b'] as $name) {
            $item = new SoftItem();
            $item->name = $name;
            $this->em->persist($item);
            $items[] = $item;
        }
        $this->em->flush();
        $this->em->remove($items[1]);
        $this->em->remove($items[2]);
        $this->em->flush();
        $this->em->clear();
        $query = $this->em->createQueryBuilder()->onlyDeleted()->fromEntity(SoftItem::class, 's')
            ->where('s.id = -1')->orWhere('1 = 1')->orderBy('s.id', 'ASC');
        self::assertCount(2, $query->getQuery()->getResult());
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM (' . $query->getSQL() . ') trash'));
        $page = (clone $query)->setFirstResult(1)->setMaxResults(1)->getQuery()->getResult();
        self::assertCount(1, $page);
        self::assertSame('trash-b', $page[0]->name);
        $restored = $this->em->findIncludingDeleted(SoftItem::class, $items[1]->id);
        $this->em->restore($restored);
        $this->em->flush();
        self::assertSame(['trash-b'], array_map(static fn (SoftItem $item): string => $item->name, $query->getQuery()->getResult()));
        self::assertSame(2, $this->em->getRepository(SoftItem::class)->count([]));
        self::assertCount(3, $this->em->createQueryBuilder()->withDeleted()->fromEntity(SoftItem::class, 's')->getQuery()->getResult());
    }

    public function testIdentityMapPreservesIdentityWithoutLeakingVisibilityAcrossQueries(): void
    {
        $item = new SoftItem();
        $this->em->persist($item);
        $this->em->flush();
        $id = $item->id;
        foreach ([true, false, true, false] as $deleted) {
            if ($deleted) {
                $this->em->remove($item);
            } else {
                $this->em->restore($item);
                $this->em->restore($item);
            }
            $this->em->flush();
            self::assertTrue($this->em->contains($item));
            self::assertSame($item, $this->em->getUnitOfWork()->tryGetById(['id' => $id], SoftItem::class));
            self::assertSame($deleted ? null : $item, $this->em->find(SoftItem::class, $id));
            self::assertSame($deleted ? null : $item, $this->em->getReference(SoftItem::class, $id));
            self::assertSame($item, $this->em->findIncludingDeleted(SoftItem::class, $id));
            $active = $this->em->createQueryBuilder()->fromEntity(SoftItem::class, 's')->getQuery()->getResult();
            $trash = $this->em->createQueryBuilder()->onlyDeleted()->fromEntity(SoftItem::class, 's')->getQuery()->getResult();
            $all = $this->em->createQueryBuilder()->withDeleted()->fromEntity(SoftItem::class, 's')->getQuery()->getResult();
            self::assertSame($deleted ? [] : [$item], $active);
            self::assertSame($deleted ? [$item] : [], $trash);
            self::assertSame([$item], $all);
            self::assertSame($deleted ? [] : [$item], $this->em->getRepository(SoftItem::class)->findBy(['id' => $id]));
            self::assertSame($deleted ? null : $item, $this->em->getRepository(SoftItem::class)->findOneBy(['id' => $id]));
            self::assertSame($deleted ? [] : [$item], $this->em->getRepository(SoftItem::class)->findAll());
            self::assertSame($deleted ? 0 : 1, $this->em->getRepository(SoftItem::class)->count([]));
            $this->em->clear();
            self::assertSame($deleted, $this->em->find(SoftItem::class, $id) === null);
            $item = $this->em->findIncludingDeleted(SoftItem::class, $id);
            self::assertSame($deleted, $item->isDeleted());
        }
    }

    public static function collectionVisibilityModes(): array
    {
        return ['ordinary lazy collections' => [1], 'batch collections' => [4]];
    }

    #[DataProvider('collectionVisibilityModes')]
    public function testRestoreReconnectsNullableTargetsAndBothCollectionKinds(int $batchSize): void
    {
        $metadata = $this->em->getClassMetadata(SoftOwner::class);
        $metadata->associationMappings['items']->batchSize = $batchSize;
        $metadata->associationMappings['tags']->batchSize = $batchSize;
        $ids = [];
        for ($i = 0; $i < 3; ++$i) {
            $owner = new SoftOwner();
            $this->em->persist($owner);
            foreach (['active', 'toggle'] as $name) {
                $item = new SoftItem();
                $item->name = $name . $i;
                $item->owner = $owner;
                $owner->tags->add($item);
                $this->em->persist($item);
                if ($name === 'toggle') {
                    $owner->favorite = $item;
                }
            }
        }
        $this->em->flush();
        foreach ($this->em->getRepository(SoftOwner::class)->findBy([], ['id' => 'ASC']) as $owner) {
            $ids[] = $owner->favorite->id;
        }
        foreach ([true, false, true, false] as $deleted) {
            foreach ($ids as $id) {
                $item = $this->em->findIncludingDeleted(SoftItem::class, $id);
                if ($deleted) {
                    $this->em->remove($item);
                } else {
                    $this->em->restore($item);
                }
            }
            $this->em->flush();
            $this->em->clear();
            foreach ($ids as $id) {
                $this->em->findIncludingDeleted(SoftItem::class, $id);
            }
            // Deleted targets already in the Identity Map must still be hidden by fresh loads.
            $owners = $this->em->getRepository(SoftOwner::class)->findBy([], ['id' => 'ASC']);
            foreach ($owners as $i => $owner) {
                self::assertSame($deleted, $owner->favorite === null);
                if (!$deleted) {
                    self::assertSame($ids[$i], $owner->favorite->id);
                }
                self::assertCount($deleted ? 1 : 2, $owner->items);
                self::assertCount($deleted ? 1 : 2, $owner->tags);
                $names = array_map(static fn (SoftItem $item): string => $item->name, $owner->items->toArray());
                sort($names);
                self::assertSame($deleted ? ['active' . $i] : ['active' . $i, 'toggle' . $i], $names);
            }
            self::assertSame(6, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_sd_owner_tags'));
            $this->em->clear();
            $owners = $this->em->createQueryBuilder()->fromEntity(SoftOwner::class, 'o')->orderBy('o.id', 'ASC')->getQuery()->getResult();
            foreach ($owners as $owner) {
                self::assertSame($deleted, $owner->favorite === null);
            }
            $this->em->clear();
            $query = $this->em->createQueryBuilder()->fromEntity(SoftOwner::class, 'o');
            $query->addJoinedEntity(SoftItem::class, 's', 'o', 'items')->leftJoin('o', 'modernorm_test_sd_items', 's', 's.owner_id = o.id');
            $owners = $query->getQuery()->getResult();
            self::assertCount(3, $owners);
            foreach ($owners as $owner) {
                self::assertCount($deleted ? 1 : 2, $owner->items);
            }
        }
    }

    public function testOnlyDeletedAndRestoreBypassTombstoneCachingAcrossManagers(): void
    {
        $item = new SoftCachedItem();
        $this->em->persist($item);
        $this->em->flush();
        $id = $item->id;
        $reader = $this->manager();
        try {
            $cached = $reader->find(SoftCachedItem::class, $id);
            self::assertNotNull($cached);
            self::assertTrue($reader->getCache()->contains(SoftCachedItem::class, $id));
            foreach ([true, false, true, false] as $deleted) {
                if ($deleted) {
                    $this->em->remove($item);
                } else {
                    $this->em->restore($item);
                }
                $this->em->flush();
                self::assertFalse($reader->getCache()->contains(SoftCachedItem::class, $id));
                $reader->clear();
                $trash = $reader->createQueryBuilder()->onlyDeleted()->fromEntity(SoftCachedItem::class, 's')->getQuery()->getResult();
                self::assertCount($deleted ? 1 : 0, $trash);
                self::assertFalse($reader->getCache()->contains(SoftCachedItem::class, $id));
                $including = $reader->findIncludingDeleted(SoftCachedItem::class, $id);
                self::assertSame($deleted, $including->isDeleted());
                self::assertSame($deleted ? null : $including, $reader->find(SoftCachedItem::class, $id));
                $reader->clear();
                $active = $reader->find(SoftCachedItem::class, $id);
                self::assertSame($deleted, $active === null);
                self::assertSame(!$deleted, $reader->getCache()->contains(SoftCachedItem::class, $id));
                $reader->clear();
                self::assertSame($deleted, $reader->getReference(SoftCachedItem::class, $id) === null);
            }
        } finally {
            $reader->getConnection()->close();
        }
    }

    public function testUniqueRestoreConflictKeepsL2DeletedUntilSuccessfulRetry(): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement('CREATE UNIQUE INDEX modernorm_test_sd_cached_active_version ON modernorm_test_sd_cached (version) WHERE deleted_at IS NULL');
        $item = new SoftCachedItem();
        $this->em->persist($item);
        $this->em->flush();
        $id = $item->id;
        $this->em->remove($item);
        $this->em->flush();
        $replacement = new SoftCachedItem();
        $replacement->name = 'replacement';
        // Restore increments the deleted item's version from 2 to 3.
        $this->em->persist($replacement);
        $this->em->flush();
        $replacementId = $replacement->id;
        // Version fields are initialized by PostgreSQL on INSERT.
        $db->executeStatement('UPDATE modernorm_test_sd_cached SET version = 3 WHERE id = ?', [$replacementId]);
        $this->em->clear();
        $this->em->find(SoftCachedItem::class, $replacementId);
        self::assertTrue($this->em->getCache()->contains(SoftCachedItem::class, $replacementId));
        $item = $this->em->findIncludingDeleted(SoftCachedItem::class, $id);
        $this->em->restore($item);
        try {
            $this->em->flush();
            self::fail('Expected unique restore conflict');
        } catch (UniqueConstraintViolationException) {
        }
        self::assertFalse($this->em->isOpen());
        self::assertFalse($db->isTransactionActive());
        $db->close();
        $this->em = $this->manager();
        self::assertNull($this->em->find(SoftCachedItem::class, $id));
        self::assertNull($this->em->getReference(SoftCachedItem::class, $id));
        self::assertFalse($this->em->getCache()->contains(SoftCachedItem::class, $id));
        self::assertSame('replacement', $this->em->find(SoftCachedItem::class, $replacementId)->name);
        $item = $this->em->findIncludingDeleted(SoftCachedItem::class, $id);
        self::assertTrue($item->isDeleted());
        self::assertSame(2, $item->version);
        $this->em->remove($this->em->find(SoftCachedItem::class, $replacementId));
        $this->em->flush();
        $this->em->restore($item);
        $this->em->flush();
        $this->em->clear();
        self::assertSame(3, $this->em->find(SoftCachedItem::class, $id)->version);
        self::assertTrue($this->em->getCache()->contains(SoftCachedItem::class, $id));
    }

    public function testFailedRestoreLeavesDeletedRowHiddenAndUncached(): void
    {
        $item = new SoftCachedItem();
        $this->em->persist($item);
        $this->em->flush();
        $id = $item->id;
        $this->em->remove($item);
        $this->em->flush();
        $listener = new class () {
            public function postUpdate(): void
            {
                throw new \RuntimeException('rollback restore');
            }
        };
        $this->em->getEventManager()->addEventListener([Events::postUpdate], $listener);
        $this->em->restore($item);
        try {
            $this->em->flush();
            self::fail('Expected restore rollback');
        } catch (\RuntimeException $error) {
            self::assertSame('rollback restore', $error->getMessage());
        }
        self::assertFalse($this->em->isOpen());
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        $this->em->getConnection()->close();
        $this->em = $this->manager();
        self::assertNull($this->em->find(SoftCachedItem::class, $id));
        self::assertNull($this->em->getReference(SoftCachedItem::class, $id));
        self::assertFalse($this->em->getCache()->contains(SoftCachedItem::class, $id));
        $item = $this->em->createQueryBuilder()->onlyDeleted()->fromEntity(SoftCachedItem::class, 's')->getQuery()->getSingleResult();
        self::assertSame(2, $item->version);
        $this->em->restore($item);
        $this->em->restore($item);
        $this->em->flush();
        self::assertSame(3, $item->version);
        self::assertSame($item, $this->em->find(SoftCachedItem::class, $id));
        $this->em->clear();
        self::assertFalse($this->em->find(SoftCachedItem::class, $id)->isDeleted());
        self::assertTrue($this->em->getCache()->contains(SoftCachedItem::class, $id));
    }


    public function testTrashRootKeepsActiveJoinedAndIndependentlyLoadedAssociations(): void
    {
        $owner = new SoftOwner();
        $favorite = new SoftItem();
        $favorite->name = 'favorite';
        $favorite->owner = $owner;
        $trash = new SoftItem();
        $trash->name = 'trash';
        $trash->owner = $owner;
        $owner->favorite = $favorite;
        foreach ([$owner, $favorite, $trash] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $trashId = $trash->id;
        $favoriteId = $favorite->id;
        $this->em->remove($trash);
        $this->em->flush();
        $this->em->clear();
        $query = $this->em->createQueryBuilder()->onlyDeleted()->fromEntity(SoftItem::class, 's');
        $loaded = $query->getQuery()->getSingleResult();
        self::assertSame($trashId, $loaded->id);
        self::assertSame('owner', $loaded->owner->name); // Initialize the ordinary lazy owner.
        self::assertSame($favoriteId, $loaded->owner->favorite->id);
        $this->em->clear();
        $query = $this->em->createQueryBuilder()->onlyDeleted()->fromEntity(SoftItem::class, 's');
        $query->addJoinedEntity(SoftOwner::class, 'o', 's', 'owner')->leftJoin('s', 'modernorm_test_sd_owners', 'o', 's.owner_id = o.id');
        $query->addJoinedEntity(SoftItem::class, 'f', 'o', 'favorite')->leftJoin('o', 'modernorm_test_sd_items', 'f', 'o.favorite_id = f.id');
        $loaded = $query->getQuery()->getSingleResult();
        self::assertSame($favoriteId, $loaded->owner->favorite->id);
        $this->em->remove($loaded->owner->favorite);
        $this->em->flush();
        $this->em->clear();
        // A tombstone already in the Identity Map cannot leak into an active joined target.
        $favorite = $this->em->findIncludingDeleted(SoftItem::class, $favoriteId);
        $rows = $query->getQuery()->getResult();
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertNull($row->owner->favorite);
        }
        $this->em->restore($favorite);
        $this->em->flush();
        $this->em->clear();
        $loaded = $query->getQuery()->getSingleResult();
        self::assertSame($trashId, $loaded->id);
        self::assertSame($favoriteId, $loaded->owner->favorite->id);
    }

    public function testReadOnlyDeletedManagedEntityCannotBeRestored(): void
    {
        $item = new SoftItem();
        $this->em->persist($item);
        $this->em->flush();
        $this->em->remove($item);
        $this->em->flush();
        $deletedAt = $item->getDeletedAt();
        $this->em->getUnitOfWork()->markReadOnly($item);
        try {
            $this->em->restore($item);
            self::fail('Expected read-only restoration rejection');
        } catch (\LogicException $error) {
            self::assertStringContainsString('read-only', $error->getMessage());
        }
        self::assertSame($deletedAt, $item->getDeletedAt());
        self::assertNull($this->em->find(SoftItem::class, $item->id));
        self::assertSame([$item], $this->em->createQueryBuilder()->onlyDeleted()->fromEntity(SoftItem::class, 's')->getQuery()->getResult());
    }

}
