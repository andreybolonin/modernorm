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

use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\DriverManager;
use ModernORM\Cache\Persister\CachedPersister;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Internal\UnitOfWork\EagerLoadCoordinator;
use ModernORM\Internal\UnitOfWork\PersisterRegistry;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\PersistentCollection;
use ModernORM\Persisters\Entity\EntityPersister;
use ModernORM\UnitOfWork;
use ModernORM\Utility\IdentifierFlattener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity]
class ComponentOwner
{
    #[ORM\Id, ORM\Column]
    public int $id = 1;

    #[ORM\OneToMany(targetEntity: ComponentChild::class, mappedBy: 'owner')]
    public Collection $children;

    #[ORM\ManyToMany(targetEntity: ComponentChild::class)]
    public Collection $tags;
}

#[ORM\Entity]
class ComponentChild
{
    #[ORM\Id, ORM\Column]
    public int $id;

    #[ORM\ManyToOne(targetEntity: ComponentOwner::class, inversedBy: 'children')]
    public ComponentOwner $owner;
}

#[ORM\Entity, ORM\Cacheable]
class ComponentCached
{
    #[ORM\Id, ORM\Column]
    public int $id;
}

final class UnitOfWorkComponentsTest extends TestCase
{
    private function manager(): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $config->setEagerFetchBatchSize(2);
        $config->setSecondLevelCache(new ArrayAdapter());

        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '19']), $config);
    }

    public function testEagerBatchesCanReenterWithoutReloadingTheActiveQueue(): void
    {
        $em = $this->manager();
        $uow = $this->createMock(UnitOfWork::class);
        $persister = $this->createMock(EntityPersister::class);
        $uow->method('getEntityPersister')->with(ComponentOwner::class)->willReturn($persister);
        $coordinator = new EagerLoadCoordinator($em, $uow, new IdentifierFlattener());
        foreach ([1, 2, 3, 4] as $id) {
            $coordinator->scheduleEntity(ComponentOwner::class, (string) $id, $id);
        }
        $coordinator->forgetEntity(ComponentOwner::class, '2');
        $batches = [];
        $persister->expects(self::exactly(3))->method('loadAll')->willReturnCallback(
            static function (array $criteria) use ($coordinator, &$batches): array {
                $batches[] = $criteria;
                if (count($batches) === 1) {
                    $coordinator->scheduleEntity(ComponentOwner::class, '5', 5);
                }
                $coordinator->trigger();
                return [];
            },
        );

        $coordinator->trigger();
        $coordinator->trigger();

        self::assertSame([['id' => [1, 3]], ['id' => [5]], ['id' => [4]]], $batches);
        self::assertFalse($em->getConnection()->isConnected());
    }

    public function testEagerCollectionsAreBatchedAndSnapshotEvenEmptyOwners(): void
    {
        $em = $this->manager();
        $uow = $this->createMock(UnitOfWork::class);
        $persister = $this->createMock(EntityPersister::class);
        $uow->method('getEntityPersister')->with(ComponentChild::class)->willReturn($persister);
        $coordinator = new EagerLoadCoordinator($em, $uow, new IdentifierFlattener());
        $metadata = $em->getClassMetadata(ComponentOwner::class);
        $owners = $children = [];
        foreach ([1, 2, 3] as $id) {
            $owner = new ComponentOwner();
            $owner->id = $id;
            $collection = new PersistentCollection($em, $em->getClassMetadata(ComponentChild::class), new \Doctrine\Common\Collections\ArrayCollection());
            $collection->setOwner($owner, $metadata->associationMappings['children']);
            $collection->setInitialized(false);
            $owner->children = $collection;
            $owners[] = $owner;
            $child = new ComponentChild();
            $child->id = $id;
            $child->owner = $owner;
            $children[] = $child;
            $coordinator->scheduleCollection($collection, $metadata);
        }
        $batches = [];
        $persister->expects(self::exactly(2))->method('loadAll')->willReturnCallback(
            static function (array $criteria) use ($coordinator, $children, &$batches): array {
                $batches[] = $criteria;
                $coordinator->trigger(); // Reentry must not load these collections again.
                return count($batches) === 1 ? [$children[1], $children[0]] : [];
            },
        );

        $coordinator->trigger();

        self::assertSame([['owner' => [$owners[0], $owners[1]]], ['owner' => [$owners[2]]]], $batches);
        foreach ($owners as $index => $owner) {
            $collection = $owner->children;
            self::assertInstanceOf(PersistentCollection::class, $collection);
            self::assertTrue($collection->isInitialized());
            self::assertSame($index === 2 ? [] : [$children[$index]], $collection->toArray());
            self::assertSame($collection->toArray(), $collection->getSnapshot());
            self::assertFalse($collection->isDirty());
        }
        self::assertFalse($em->getConnection()->isConnected());
    }

    public function testClearDiscardsBothDeferredEntitiesAndCollections(): void
    {
        $em = $this->manager();
        $uow = $this->createMock(UnitOfWork::class);
        $uow->expects(self::never())->method('getEntityPersister');
        $coordinator = new EagerLoadCoordinator($em, $uow, new IdentifierFlattener());
        $owner = new ComponentOwner();
        $metadata = $em->getClassMetadata(ComponentOwner::class);
        $collection = new PersistentCollection($em, $em->getClassMetadata(ComponentChild::class), new \Doctrine\Common\Collections\ArrayCollection());
        $collection->setOwner($owner, $metadata->associationMappings['children']);
        $collection->setInitialized(false);
        $coordinator->scheduleCollection($collection, $metadata);
        $coordinator->scheduleEntity(ComponentOwner::class, '1', 1);

        $coordinator->clear();
        $coordinator->trigger();

        self::assertFalse($collection->isInitialized());
    }

    public function testPersistersRetainTheirIdentityAcrossUnitOfWorkClear(): void
    {
        $em = $this->manager();
        $uow = $em->getUnitOfWork();
        $metadata = $em->getClassMetadata(ComponentOwner::class);
        $entity = $uow->getEntityPersister(ComponentCached::class);
        $children = $uow->getCollectionPersister($metadata->associationMappings['children']);
        $tags = $uow->getCollectionPersister($metadata->associationMappings['tags']);

        $uow->clear();

        self::assertSame($entity, $uow->getEntityPersister(ComponentCached::class));
        self::assertInstanceOf(CachedPersister::class, $entity);
        self::assertSame($children, $uow->getCollectionPersister($metadata->associationMappings['children']));
        self::assertSame($tags, $uow->getCollectionPersister($metadata->associationMappings['tags']));
        self::assertNotSame($children, $tags);
        self::assertFalse($em->getConnection()->isConnected());
    }

    public function testCacheCallbacksOnlyVisitInstantiatedCachedPersisters(): void
    {
        $em = $this->manager();
        $registry = new PersisterRegistry($em, true);
        $visited = [];
        $callback = static function (CachedPersister $persister) use (&$visited): void {
            $visited[] = $persister;
        };
        $registry->forEachCachedPersister($callback);
        self::assertSame([], $visited);
        $registry->getEntityPersister(ComponentChild::class);
        $cached = $registry->getEntityPersister(ComponentCached::class);
        $registry->getEntityPersister(ComponentCached::class);
        $registry->forEachCachedPersister($callback);

        self::assertSame([$cached], $visited);
        self::assertFalse($em->getConnection()->isConnected());
    }
}
