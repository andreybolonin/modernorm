<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Result;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Internal\Hydration\AbstractHydrator;
use ModernORM\Internal\Hydration\ObjectHydrator;
use ModernORM\Internal\Hydration\SimpleObjectHydrator;
use ModernORM\Internal\UnitOfWork\FlatSqlHydrationPlan;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Mapping\PropertyAccessors\PropertyAccessor;
use ModernORM\Query;
use ModernORM\Query\ResultSetMapping;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use ReflectionReference;
use Error;
use LogicException;

#[ORM\Entity]
class FlatSqlRecord
{
    public static int $hooks = 0;
    public static int $constructors = 0;
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column(nullable: true)] private ?string $name = 'seed';
    #[ORM\Column] protected int $quantity;
    #[ORM\Column] public float $price;
    #[ORM\Column] public bool $active;
    #[ORM\Column] public string $hooked = 'seed' {
        set {
            ++self::$hooks;
            $this->hooked = $value;
        }
    }
    public function __construct()
    {
        ++self::$constructors;
        $this->name = null;
    }
    public function name(): ?string
    {
        return $this->name;
    }
}

#[ORM\Entity]
class FlatSqlReadonlyRecord
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column] public readonly string $label;
    public function __construct()
    {
        $this->label = 'constructor';
    }
}

#[ORM\MappedSuperclass]
class FlatSqlBase
{
    #[ORM\Id, ORM\Column] public int $id;
}

#[ORM\Entity]
class FlatSqlInherited extends FlatSqlBase
{
    #[ORM\Column] public string $name;
}

class ReferenceInjectingFlatHydrator extends ObjectHydrator
{
    public string $name = 'custom';
    protected function gatherRowData(array $data, array &$id, array &$nonemptyComponents): array
    {
        $result = parent::gatherRowData($data, $id, $nonemptyComponents);
        $result['data']['r']['name'] = & $this->name;
        return $result;
    }
}

final class FlatSqlHydrationPlanTest extends TestCase
{
    private EntityManager $em;
    protected function setUp(): void
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '18']), $config);
        FlatSqlRecord::$hooks = FlatSqlRecord::$constructors = 0;
    }
    public static function hydrators(): array
    {
        return ['object' => [ObjectHydrator::class], 'simple' => [SimpleObjectHydrator::class]];
    }
    private function mapping(string $class = FlatSqlRecord::class): ResultSetMapping
    {
        $rsm = new ResultSetMapping();
        $rsm->addEntityResult($class, 'r');
        foreach ($this->em->getClassMetadata($class)->fieldMappings as $field => $_) {
            $rsm->addFieldResult('r', $field, $field);
        }
        return $rsm;
    }
    private function row(int $id): array
    {
        return ['id' => (string) $id, 'name' => 'name-' . $id, 'quantity' => '7', 'price' => '1.25', 'active' => '1', 'hooked' => 'raw'];
    }
    /** @param list<array<string, mixed>> $rows */
    private function statement(array $rows): Result
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturnOnConsecutiveCalls(...[...$rows, false]);
        $result->expects(self::once())->method('free');
        return $result;
    }
    private function activePlan(AbstractHydrator $hydrator): ?FlatSqlHydrationPlan
    {
        $hints = (new ReflectionProperty(AbstractHydrator::class, 'hints'))->getValue($hydrator);
        return $hints[FlatSqlHydrationPlan::HINT] ?? null;
    }
    /** @param class-string<AbstractHydrator> $class */
    #[DataProvider('hydrators')]
    public function testOnePlanPerSqlContextAndCleanupOnReuse(string $class): void
    {
        $hydrator = new $class($this->em);
        $secondRow = $this->row(2);
        $secondRow['name'] = null;
        $iterator = $hydrator->toIterable($this->statement([$this->row(1), $secondRow]), $this->mapping());
        $first = $iterator->current();
        $plan = $this->activePlan($hydrator);
        if (FlatSqlHydrationPlan::available()) {
            self::assertInstanceOf(FlatSqlHydrationPlan::class, $plan);
        } else {
            self::assertNull($plan);
        }
        self::assertSame('name-1', $first->name());
        $iterator->next();
        $second = $iterator->current();
        self::assertSame($plan, $this->activePlan($hydrator));
        self::assertNull($second->name());
        self::assertSame(1.25, $second->price);
        self::assertTrue($second->active);
        self::assertSame('raw', $second->hooked);
        self::assertSame(7, $this->em->getClassMetadata(FlatSqlRecord::class)->propertyAccessors['quantity']->getValue($second));
        $iterator->next();
        self::assertNull($this->activePlan($hydrator));
        $other = $hydrator->toIterable($this->statement([array_reverse($this->row(3), true)]), $this->mapping());
        self::assertSame(3, $other->current()->id);
        if ($plan !== null) {
            self::assertNotSame($plan, $this->activePlan($hydrator));
        }
        $other->next();
        self::assertSame(0, FlatSqlRecord::$hooks);
        self::assertSame(0, FlatSqlRecord::$constructors);
        self::assertFalse($this->em->getConnection()->isConnected());
    }
    /** @param class-string<AbstractHydrator> $class */
    #[DataProvider('hydrators')]
    public function testSqlProducerCopiesRawReferencesByValue(string $class): void
    {
        $name = 'reference';
        $row = $this->row(1);
        $row['name'] = & $name;
        $hydrator = new $class($this->em);
        $iterator = $hydrator->toIterable($this->statement([$row]), $this->mapping());
        $entity = $iterator->current();
        $snapshot = $this->em->getUnitOfWork()->getOriginalEntityData($entity);
        self::assertNull(ReflectionReference::fromArrayElement($snapshot, 'name'));
        $name = 'changed-source';
        self::assertSame('reference', $entity->name());
        $iterator->next();
    }
    /** @param class-string<AbstractHydrator> $class */
    #[DataProvider('hydrators')]
    public function testRuntimeAccessorReplacementFallsBackOnLaterRows(string $class): void
    {
        $hydrator = new $class($this->em);
        $iterator = $hydrator->toIterable($this->statement([$this->row(1), $this->row(2)]), $this->mapping());
        self::assertSame('name-1', $iterator->current()->name());
        $metadata = $this->em->getClassMetadata(FlatSqlRecord::class);
        $metadata->propertyAccessors['name'] = new class ($metadata->propertyAccessors['name']) implements PropertyAccessor {
            public function __construct(private PropertyAccessor $parent)
            {
            }
            public function setValue(object $object, mixed $value): void
            {
                $this->parent->setValue($object, 'custom:' . $value);
            }
            public function getValue(object $object): mixed
            {
                return $this->parent->getValue($object);
            }
            public function getUnderlyingReflector(): ReflectionProperty
            {
                throw new LogicException('Do not inspect custom accessors.');
            }
        };
        $iterator->next();
        self::assertSame('custom:name-2', $iterator->current()->name());
        $iterator->next();
    }
    /** @param class-string<AbstractHydrator> $class */
    #[DataProvider('hydrators')]
    public function testExistingAndRefreshedObjectsKeepTheirOriginalPaths(string $class): void
    {
        $hydrator = new $class($this->em);
        $entity = $hydrator->hydrateAll($this->statement([$this->row(1)]), $this->mapping())[0];
        $metadata = $this->em->getClassMetadata(FlatSqlRecord::class);
        $metadata->propertyAccessors['name']->setValue($entity, 'local');
        $same = $hydrator->hydrateAll($this->statement([$this->row(1)]), $this->mapping())[0];
        self::assertSame($entity, $same);
        self::assertSame('local', $entity->name());
        $iterator = $hydrator->toIterable($this->statement([$this->row(1)]), $this->mapping(), [Query::HINT_REFRESH => true]);
        self::assertSame($entity, $iterator->current());
        self::assertSame('name-1', $entity->name());
        self::assertNull($this->activePlan($hydrator));
        $iterator->next();
    }
    public function testCustomHydratorWithReferencesNeverUsesSqlPlan(): void
    {
        $hydrator = new ReferenceInjectingFlatHydrator($this->em);
        $iterator = $hydrator->toIterable($this->statement([$this->row(1)]), $this->mapping());
        $entity = $iterator->current();
        self::assertNull($this->activePlan($hydrator));
        self::assertSame('custom', $entity->name());
        $hydrator->name = 'changed';
        self::assertSame('custom', $this->em->getClassMetadata(FlatSqlRecord::class)->propertyAccessors['name']->getValue($entity));
        $iterator->next();
    }
    /** @param class-string<AbstractHydrator> $class */
    #[DataProvider('hydrators')]
    public function testNullHookedValuePreservesErrorAndRegisteredPrefix(string $class): void
    {
        $row = $this->row(1);
        $row['hooked'] = null;
        $hydrator = new $class($this->em);
        try {
            $hydrator->hydrateAll($this->statement([$row]), $this->mapping());
            self::fail('Unsetting a non-nullable hooked property must fail.');
        } catch (Error $error) {
            self::assertSame(Error::class, $error::class);
            $entity = $this->em->getUnitOfWork()->tryGetById(['id' => 1], FlatSqlRecord::class);
            self::assertInstanceOf(FlatSqlRecord::class, $entity);
            self::assertSame('name-1', $entity->name());
            self::assertSame('seed', $entity->hooked);
            self::assertNull($this->activePlan($hydrator));
        }
    }
    /** @param class-string<AbstractHydrator> $class */
    #[DataProvider('hydrators')]
    public function testReadonlyMappingUsesCompatibilityPath(string $class): void
    {
        $hydrator = new $class($this->em);
        $iterator = $hydrator->toIterable($this->statement([['id' => '1', 'label' => 'row']]), $this->mapping(FlatSqlReadonlyRecord::class));
        self::assertSame('row', $iterator->current()->label);
        self::assertNull($this->activePlan($hydrator));
        $iterator->next();
    }
    public function testCompilationRejectsIncompleteAndInheritedMetadata(): void
    {
        self::assertNull(FlatSqlHydrationPlan::compile($this->em->getClassMetadata(FlatSqlRecord::class), ['id']));
        self::assertNull(FlatSqlHydrationPlan::compile($this->em->getClassMetadata(FlatSqlInherited::class), ['id', 'name']));
    }
}
