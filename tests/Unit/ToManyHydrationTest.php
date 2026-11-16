<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Internal\UnitOfWork\EntityHydrator;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\PersistentCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[ORM\Entity]
class ToManyHydrationOwner
{
    #[ORM\Id, ORM\Column] public int $id = 1;
    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: ToManyHydrationChild::class)]
    private Collection $items;
    public function items(): Collection
    {
        return $this->items;
    }
}

#[ORM\Entity]
class ToManyHydrationChild
{
    #[ORM\Id, ORM\Column] public int $id = 1;
    #[ORM\ManyToOne(targetEntity: ToManyHydrationOwner::class)]
    public ?ToManyHydrationOwner $owner = null;
}

final class ToManyHydrationTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '18']), $config);
    }

    public function testNewCollectionIsAssignedUninitializedWithoutLoadingOrRegistration(): void
    {
        $owner = new ToManyHydrationOwner();
        $metadata = $this->em->getClassMetadata($owner::class);
        $association = $metadata->associationMappings['items'];
        self::assertTrue($association->isToMany());
        $collection = EntityHydrator::hydrateToMany($this->em, $this->em->getClassMetadata(ToManyHydrationChild::class), $owner, $association, $metadata->propertyAccessors['items']);
        self::assertSame($collection, $owner->items());
        self::assertSame($owner, $collection->getOwner());
        self::assertSame($association, $collection->getMapping());
        self::assertFalse($collection->isInitialized());
        self::assertFalse($collection->isDirty());
        self::assertSame([], $collection->getSnapshot());
        self::assertSame([], $collection->unwrap()->toArray());
        self::assertSame([], $this->em->getUnitOfWork()->getIdentityMap());
        self::assertFalse($this->em->getConnection()->isConnected());
    }

    public static function states(): array
    {
        return ['initialized' => [true], 'uninitialized' => [false]];
    }

    #[DataProvider('states')]
    public function testSuppliedCollectionRetainsContentsSnapshotAndDirtyState(bool $initialized): void
    {
        $owner = new ToManyHydrationOwner();
        $metadata = $this->em->getClassMetadata($owner::class);
        $association = $metadata->associationMappings['items'];
        self::assertTrue($association->isToMany());
        $child = new ToManyHydrationChild();
        $collection = new PersistentCollection($this->em, $this->em->getClassMetadata($child::class), new ArrayCollection([$child]));
        $collection->setOwner(new ToManyHydrationOwner(), $association);
        $collection->takeSnapshot();
        $collection->setDirty(true);
        $collection->setInitialized($initialized);
        $result = EntityHydrator::hydrateToMany($this->em, $this->em->getClassMetadata($child::class), $owner, $association, $metadata->propertyAccessors['items'], $collection);
        self::assertSame($collection, $result);
        self::assertSame($collection, $owner->items());
        self::assertSame($owner, $collection->getOwner());
        self::assertSame($initialized, $collection->isInitialized());
        self::assertTrue($collection->isDirty());
        self::assertSame([$child], $collection->unwrap()->toArray());
        self::assertSame([$child], $collection->getSnapshot());
        self::assertFalse($this->em->getConnection()->isConnected());
    }

    public function testAssignmentFailureOccursAfterOwnerAndInitializationChanges(): void
    {
        $owner = new ToManyHydrationOwner();
        $metadata = $this->em->getClassMetadata($owner::class);
        $association = $metadata->associationMappings['items'];
        self::assertTrue($association->isToMany());
        $accessor = new class () implements ORM\PropertyAccessors\PropertyAccessor {
            public ?PersistentCollection $observed = null;
            public function getValue(object $object): mixed
            {
                return null;
            }
            public function setValue(object $object, mixed $value): void
            {
                if (! $value instanceof PersistentCollection) {
                    throw new RuntimeException('wrong value');
                }
                $this->observed = $value;
                throw new RuntimeException('assignment failed');
            }
            public function getUnderlyingReflector(): \ReflectionProperty
            {
                return new \ReflectionProperty(ToManyHydrationOwner::class, 'items');
            }
        };
        try {
            EntityHydrator::hydrateToMany($this->em, $this->em->getClassMetadata(ToManyHydrationChild::class), $owner, $association, $accessor);
            self::fail('Expected assignment failure');
        } catch (RuntimeException $error) {
            self::assertSame('assignment failed', $error->getMessage());
        }
        self::assertNotNull($accessor->observed);
        self::assertSame($owner, $accessor->observed->getOwner());
        self::assertSame($association, $accessor->observed->getMapping());
        self::assertFalse($accessor->observed->isInitialized());
        self::assertFalse($this->em->getConnection()->isConnected());
    }

    public function testCoordinatorUsesSuppliedCollectionAndRegistersItsSnapshot(): void
    {
        $target = $this->em->getClassMetadata(ToManyHydrationChild::class);
        $collection = new PersistentCollection($this->em, $target, new ArrayCollection());
        $collection->setDirty(true);
        $uow = $this->em->getUnitOfWork();
        $owner = $uow->createEntity(ToManyHydrationOwner::class, ['id' => 7, 'items' => $collection]);
        self::assertInstanceOf(ToManyHydrationOwner::class, $owner);
        self::assertSame($collection, $owner->items());
        self::assertSame($owner, $collection->getOwner());
        self::assertSame($collection, $uow->getOriginalEntityData($owner)['items']);
        self::assertTrue($collection->isInitialized());
        self::assertTrue($collection->isDirty());
        self::assertFalse($this->em->getConnection()->isConnected());
    }
}
