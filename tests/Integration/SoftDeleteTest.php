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
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
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
}
