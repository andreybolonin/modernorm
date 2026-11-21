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

use Closure;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\DriverManager;
use ModernORM\Behavior\SoftDeleteTrait;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Event\PrePersistEventArgs;
use ModernORM\Event\PreRemoveEventArgs;
use ModernORM\Events;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\PersistentCollection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[ORM\Entity]
class CascadeGraphNode
{
    /** @var Collection<int, self> */
    #[ORM\ManyToMany(targetEntity: self::class, cascade: ['persist', 'remove'])]
    public Collection $links;

    public function __construct(#[ORM\Id, ORM\Column] public int $id)
    {
        $this->links = new ArrayCollection();
    }
}

#[ORM\Entity, ORM\SoftDelete]
class CascadeSoftNode
{
    use SoftDeleteTrait;

    public function __construct(#[ORM\Id, ORM\Column] public int $id)
    {
    }
}

final class CascadeProcessorTest extends TestCase
{
    private function manager(): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '19']), $config);
    }

    /** @return array{CascadeGraphNode, CascadeGraphNode, CascadeGraphNode} */
    private function graph(): array
    {
        $root = new CascadeGraphNode(1);
        $branch = new CascadeGraphNode(2);
        $shared = new CascadeGraphNode(3);
        $root->links = new ArrayCollection([$branch, $shared]);
        $branch->links = new ArrayCollection([$root, $shared]);
        return [$root, $branch, $shared];
    }

    /** @param Closure(object): void $callback */
    private function listen(EntityManager $em, string $event, Closure $callback): void
    {
        $em->getEventManager()->addEventListener([$event], new class ($callback) {
            public function __construct(private readonly Closure $callback)
            {
            }

            public function prePersist(PrePersistEventArgs $event): void
            {
                ($this->callback)($event->getObject());
            }

            public function preRemove(PreRemoveEventArgs $event): void
            {
                ($this->callback)($event->getObject());
            }
        });
    }

    public function testPersistVisitsCyclesAndSharedChildrenOnceInDepthFirstOrder(): void
    {
        $em = $this->manager();
        [$root, $branch, $shared] = $this->graph();
        $visited = [];
        $this->listen($em, Events::prePersist, static function (object $entity) use (&$visited): void {
            $visited[] = $entity;
        });

        $em->persist($root);

        self::assertSame([$root, $branch, $shared], $visited);
        self::assertSame($visited, array_values($em->getUnitOfWork()->getScheduledEntityInsertions()));
        self::assertFalse($em->getConnection()->isConnected());
    }

    public function testRemoveVisitsChildrenBeforeParentsWithoutRepeatedCycleEvents(): void
    {
        $em = $this->manager();
        [$root, $branch, $shared] = $this->graph();
        $uow = $em->getUnitOfWork();
        foreach ([$root, $branch, $shared] as $entity) {
            $uow->registerManaged($entity, ['id' => $entity->id], []);
        }
        $visited = [];
        $this->listen($em, Events::preRemove, static function (object $entity) use (&$visited, $uow, $root): void {
            self::assertTrue($uow->isInIdentityMap($root));
            $visited[] = $entity;
        });

        $em->remove($root);

        self::assertSame([$shared, $branch, $root], $visited);
        self::assertSame($visited, array_values($uow->getScheduledEntityDeletions()));
        self::assertFalse($em->getConnection()->isConnected());
    }

    public function testPersistUnwrapsAnUninitializedCollectionWithoutLoadingIt(): void
    {
        $em = $this->manager();
        $root = new CascadeGraphNode(1);
        $child = new CascadeGraphNode(2);
        $metadata = $em->getClassMetadata(CascadeGraphNode::class);
        $collection = new PersistentCollection($em, $metadata, new ArrayCollection([$child]));
        $collection->setOwner($root, $metadata->associationMappings['links']);
        $collection->setInitialized(false);
        $root->links = $collection;

        $em->persist($root);

        self::assertFalse($collection->isInitialized());
        self::assertSame([$root, $child], array_values($em->getUnitOfWork()->getScheduledEntityInsertions()));
        self::assertFalse($em->getConnection()->isConnected());
    }

    public function testRemoveKeepsTheCollectedChildrenWhenAListenerChangesTheGraph(): void
    {
        $em = $this->manager();
        $root = new CascadeGraphNode(1);
        $first = new CascadeGraphNode(2);
        $second = new CascadeGraphNode(3);
        $root->links = new ArrayCollection([$first, $second]);
        foreach ([$root, $first, $second] as $entity) {
            $em->getUnitOfWork()->registerManaged($entity, ['id' => $entity->id], []);
        }
        $this->listen($em, Events::preRemove, static function (object $entity) use ($first, $second, $root): void {
            if ($entity === $first) {
                $root->links->removeElement($second);
            }
        });

        $em->remove($root);

        self::assertSame([$first, $second, $root], array_values($em->getUnitOfWork()->getScheduledEntityDeletions()));
    }

    public function testAListenerFailureDoesNotRetainTheVisitedGraphForTheNextPersist(): void
    {
        $em = $this->manager();
        [$root, $branch, $shared] = $this->graph();
        $attempts = 0;
        $this->listen($em, Events::prePersist, static function (object $entity) use ($branch, &$attempts): void {
            if ($entity === $branch && ++$attempts === 1) {
                throw new RuntimeException('listener failure');
            }
        });
        try {
            $em->persist($root);
            self::fail('The listener failure must propagate.');
        } catch (RuntimeException $error) {
            self::assertSame('listener failure', $error->getMessage());
        }

        $em->persist($root);

        self::assertSame(2, $attempts);
        self::assertSame([$root, $branch, $shared], array_values($em->getUnitOfWork()->getScheduledEntityInsertions()));
    }

    public function testHardRemoveRestoresSoftDeleteModeWhenAListenerThrows(): void
    {
        $em = $this->manager();
        $root = new CascadeSoftNode(1);
        $uow = $em->getUnitOfWork();
        $uow->registerManaged($root, ['id' => 1], ['deletedAt' => null]);
        $this->listen($em, Events::preRemove, static function (object $entity): void {
            throw new RuntimeException('physical removal failed');
        });
        try {
            $uow->hardRemove($root);
            self::fail('The physical removal listener failure must propagate.');
        } catch (RuntimeException $error) {
            self::assertSame('physical removal failed', $error->getMessage());
        }

        $em->remove($root);

        self::assertTrue($root->isDeleted());
        self::assertSame([], $uow->getScheduledEntityDeletions());
        self::assertTrue($uow->isInIdentityMap($root));
        self::assertFalse($em->getConnection()->isConnected());
    }
}
