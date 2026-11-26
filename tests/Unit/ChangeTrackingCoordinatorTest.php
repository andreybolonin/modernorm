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

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Exception\ORMInvalidArgumentException;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Mapping\ToManyAssociationMapping;
use ModernORM\PersistentCollection;
use ModernORM\UnitOfWork;
use PHPUnit\Framework\TestCase;

#[ORM\Entity]
class TrackingCoordinatorRecord
{
    #[ORM\Column]
    public string $name = 'before';

    #[ORM\ManyToOne(targetEntity: self::class, cascade: ['persist'])]
    public ?self $parent = null;

    public function __construct(#[ORM\Id, ORM\Column] public int $id)
    {
    }
}

class TrackingObservingUnitOfWork extends UnitOfWork
{
    /** @var list<object> */
    public array $observed = [];

    public function computeChangeSet(ClassMetadata $class, object $entity): void
    {
        $this->observed[] = $entity;
        parent::computeChangeSet($class, $entity);
    }
}

#[ORM\Entity]
class TrackingGroupParent
{
    #[ORM\Id, ORM\Column]
    public int $id;

    #[ORM\Column(type: 'json')]
    public array $payload = ['value' => 1];

    #[ORM\OneToMany(targetEntity: TrackingGroupChild::class, mappedBy: 'parent')]
    public Collection $children;

    #[ORM\ManyToMany(targetEntity: self::class, inversedBy: 'relatedBy')]
    public Collection $related;

    #[ORM\ManyToMany(targetEntity: self::class, mappedBy: 'related')]
    public Collection $relatedBy;

    #[ORM\ManyToOne(targetEntity: self::class)]
    public ?self $leader = null;

    #[ORM\OneToOne(targetEntity: self::class)]
    public ?self $partner = null;
}

#[ORM\Entity]
class TrackingGroupChild
{
    #[ORM\Id, ORM\Column]
    public int $id;

    #[ORM\ManyToOne(targetEntity: TrackingGroupParent::class, inversedBy: 'children')]
    public TrackingGroupParent $parent;
}

#[ORM\Entity, ORM\HasLifecycleCallbacks]
class TrackingGroupCallbackRecord
{
    #[ORM\Id, ORM\Column]
    public int $id;

    #[ORM\Column]
    public string $name = 'before';

    #[ORM\PreFlush]
    public function changeName(): void
    {
        $this->name = 'after';
    }
}

final class ChangeTrackingCoordinatorTest extends TestCase
{
    private function manager(): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '19']), $config);
    }

    public function testExistingChangesetsRemainReferencesAndValueCopiesStayIndependent(): void
    {
        $em = $this->manager();
        $record = new TrackingCoordinatorRecord(1);
        $uow = $em->getUnitOfWork();
        $uow->registerManaged($record, ['id' => 1], ['id' => 1, 'name' => 'before', 'parent' => null]);
        $record->name = 'after';
        $uow->computeChangeSets();
        $reference = & $uow->getEntityChangeSet($record);
        $copy = $reference;
        $reference['name'][1] = 'listener override';

        self::assertSame(['before', 'listener override'], $uow->getEntityChangeSet($record)['name']);
        self::assertSame(['before', 'after'], $copy['name']);
        self::assertSame('after', $uow->getOriginalEntityData($record)['name']);
        self::assertFalse($em->getConnection()->isConnected());
    }

    public function testMissingChangesetReferenceDoesNotCreateATrackedEntry(): void
    {
        $em = $this->manager();
        $record = new TrackingCoordinatorRecord(1);
        $uow = $em->getUnitOfWork();
        $missing = & $uow->getEntityChangeSet($record);
        $missing['name'] = ['before', 'local only'];

        self::assertSame([], $uow->getEntityChangeSet($record));
        self::assertSame([], $uow->getScheduledEntityUpdates());
    }

    public function testExplicitSchedulingAndChangeSetsAreClearedWithTheContext(): void
    {
        $em = $this->manager();
        $em->getClassMetadata(TrackingCoordinatorRecord::class)->setChangeTrackingPolicy(ORM\ClassMetadata::CHANGETRACKING_DEFERRED_EXPLICIT);
        $record = new TrackingCoordinatorRecord(1);
        $uow = $em->getUnitOfWork();
        $uow->registerManaged($record, ['id' => 1], ['id' => 1, 'name' => 'before', 'parent' => null]);
        $record->name = 'after';
        $uow->computeChangeSets();
        self::assertSame([], $uow->getEntityChangeSet($record));
        $em->persist($record);
        $uow->computeChangeSets();
        self::assertTrue($uow->isScheduledForDirtyCheck($record));
        self::assertSame(['before', 'after'], $uow->getEntityChangeSet($record)['name']);
        $uow->markReadOnly($record);

        $uow->clear();

        self::assertFalse($uow->isScheduledForDirtyCheck($record));
        self::assertFalse($uow->isReadOnly($record));
        self::assertSame([], $uow->getEntityChangeSet($record));
        self::assertSame([], $uow->getOriginalEntityData($record));
    }

    public function testReadOnlyMarksSurviveEmptyFlushCleanup(): void
    {
        $em = $this->manager();
        $record = new TrackingCoordinatorRecord(1);
        $uow = $em->getUnitOfWork();
        $uow->registerManaged($record, ['id' => 1], ['id' => 1, 'name' => 'before', 'parent' => null]);
        $uow->markReadOnly($record);
        $record->name = 'after';

        $em->flush();

        self::assertTrue($uow->isReadOnly($record));
        self::assertSame([], $uow->getEntityChangeSet($record));
        self::assertSame([], $uow->getScheduledEntityUpdates());
        self::assertSame('before', $uow->getOriginalEntityData($record)['name']);
        self::assertFalse($em->getConnection()->isConnected());
    }

    public function testReachabilityAndTheMainScanStillDispatchThroughOverriddenUnitOfWorkMethods(): void
    {
        $em = $this->manager();
        $uow = new TrackingObservingUnitOfWork($em);
        $root = new TrackingCoordinatorRecord(1);
        $parent = new TrackingCoordinatorRecord(2);
        $uow->registerManaged($root, ['id' => 1], ['id' => 1, 'name' => 'before', 'parent' => null]);
        $root->parent = $parent;

        $uow->computeChangeSets();

        self::assertSame([$root, $parent], $uow->observed);
        self::assertTrue($uow->isScheduledForInsert($parent));
        self::assertTrue($uow->isScheduledForUpdate($root));
        self::assertSame([null, $parent], $uow->getEntityChangeSet($root)['parent']);
        self::assertFalse($em->getConnection()->isConnected());
    }

    public function testLargeManagedGroupStillTracksChangedFieldsAndDirtyCollections(): void
    {
        $em = $this->manager();
        $uow = $em->getUnitOfWork();
        $parents = $this->managedCollectionGroup($em);

        $em->flush();
        self::assertSame([], $uow->getScheduledEntityUpdates());
        self::assertSame([], $uow->getScheduledCollectionUpdates());
        self::assertFalse($em->getConnection()->isConnected());

        $parent = $parents[511];
        $owning = $parent->related;
        $inverse = $parent->relatedBy;
        self::assertInstanceOf(PersistentCollection::class, $owning);
        self::assertInstanceOf(PersistentCollection::class, $inverse);
        $owning->setDirty(true);
        $inverse->setDirty(true);
        $uow->computeChangeSets();
        $updates = $uow->getScheduledCollectionUpdates();
        self::assertSame($owning, $updates[spl_object_id($owning)]);
        self::assertSame($inverse, $updates[spl_object_id($inverse)]);
        self::assertTrue($uow->isScheduledForUpdate($parent));

        $parent->payload['value'] = 2;
        $uow->computeChangeSets();
        self::assertSame([['value' => 1], ['value' => 2]], $uow->getEntityChangeSet($parent)['payload']);
    }

    public function testCleanNonemptyCollectionStillChecksReachability(): void
    {
        $em = $this->manager();
        $parents = $this->managedCollectionGroup($em);
        $collection = $parents[255]->related;
        self::assertInstanceOf(PersistentCollection::class, $collection);
        $related = new TrackingGroupParent();
        $related->id = 513;
        $collection->unwrap()->add($related);
        self::assertFalse($collection->isDirty());
        $parents[511]->payload['value'] = 2;

        $this->expectException(ORMInvalidArgumentException::class);
        $this->expectExceptionMessage('A new entity was found through the relationship');
        $em->flush();
    }

    public function testCleanNonemptyCollectionsCanShareManagedTargets(): void
    {
        $em = $this->manager();
        $uow = $em->getUnitOfWork();
        $parents = $this->managedCollectionGroup($em);
        $target = $parents[0];
        foreach ($parents as $parent) {
            foreach ([$parent->related, $parent->relatedBy] as $collection) {
                self::assertInstanceOf(PersistentCollection::class, $collection);
                $collection->hydrateAdd($target);
                $collection->takeSnapshot();
            }
        }

        $em->flush();

        self::assertCount(1, $parents[511]->related);
        self::assertSame($target, $parents[511]->related->first());
        self::assertSame([], $uow->getScheduledCollectionUpdates());
        self::assertFalse($em->getConnection()->isConnected());
    }

    public function testLargeGroupStillTracksChangesFromExplicitNullToOneAssociations(): void
    {
        $em = $this->manager();
        $uow = $em->getUnitOfWork();
        $parents = $this->managedCollectionGroup($em);
        $em->flush();
        self::assertFalse($em->getConnection()->isConnected());

        $parent = $parents[511];
        $parent->leader = $parents[0];
        $parent->partner = $parents[1];
        $uow->computeChangeSets();

        $changes = $uow->getEntityChangeSet($parent);
        self::assertSame([null, $parents[0]], $changes['leader']);
        self::assertSame([null, $parents[1]], $changes['partner']);
    }

    public function testLargeGroupWithManagedManyToOneStillTracksReferenceChanges(): void
    {
        $em = $this->manager();
        $uow = $em->getUnitOfWork();
        $parents = $this->managedCollectionGroup($em);
        $leader = $parents[0];
        foreach ($parents as $parent) {
            $parent->leader = $leader;
            $uow->setOriginalEntityProperty(spl_object_id($parent), 'leader', $leader);
        }

        $em->flush();
        self::assertFalse($em->getConnection()->isConnected());

        $parent = $parents[511];
        $parent->leader = $parents[1];
        $uow->computeChangeSets();

        self::assertSame([$leader, $parents[1]], $uow->getEntityChangeSet($parent)['leader']);
    }

    public function testUnchangedManyToOneStillChecksAnUnmanagedTarget(): void
    {
        $em = $this->manager();
        $uow = $em->getUnitOfWork();
        $parents = $this->managedCollectionGroup($em);
        $leader = new TrackingGroupParent();
        $leader->id = 513;
        $parent = $parents[255];
        $parent->leader = $leader;
        $uow->setOriginalEntityProperty(spl_object_id($parent), 'leader', $leader);
        $parents[511]->payload['value'] = 2;

        $this->expectException(ORMInvalidArgumentException::class);
        $this->expectExceptionMessage('A new entity was found through the relationship');
        $em->flush();
    }

    /** @return list<TrackingGroupParent> */
    private function managedCollectionGroup(EntityManager $em): array
    {
        $uow = $em->getUnitOfWork();
        $class = $em->getClassMetadata(TrackingGroupParent::class);
        $mappings = [];
        foreach (['children', 'related', 'relatedBy'] as $field) {
            $mapping = $class->getAssociationMapping($field);
            self::assertInstanceOf(ToManyAssociationMapping::class, $mapping);
            $mappings[$field] = [$mapping, $em->getClassMetadata($mapping->targetEntity)];
        }
        $parents = [];
        for ($id = 1; $id <= 512; ++$id) {
            $parent = new TrackingGroupParent();
            $parent->id = $id;
            foreach ($mappings as $field => [$mapping, $targetClass]) {
                $collection = new PersistentCollection($em, $targetClass, new ArrayCollection());
                $collection->setOwner($parent, $mapping);
                $parent->{$field} = $collection;
            }
            $uow->registerManaged($parent, ['id' => $id], (array) $parent);
            $parents[] = $parent;
        }
        return $parents;
    }

    public function testLargeUnchangedGroupStillInvokesEntityPreFlushCallbacks(): void
    {
        $em = $this->manager();
        $uow = $em->getUnitOfWork();
        for ($id = 1; $id <= 64; ++$id) {
            $record = new TrackingGroupCallbackRecord();
            $record->id = $id;
            $uow->registerManaged($record, ['id' => $id], (array) $record);
        }

        $uow->computeChangeSets();

        self::assertCount(64, $uow->getScheduledEntityUpdates());
        self::assertSame(['before', 'after'], $uow->getEntityChangeSet($record)['name']);
    }
}
