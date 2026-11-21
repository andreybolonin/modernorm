<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use LogicException;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Query;
use ModernORM\Internal\UnitOfWork\EntityHydrator;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Mapping\PropertyAccessors\PropertyAccessor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use TypeError;
use ValueError;

enum HydrationState: string
{
    case Active = 'active';
}

enum HydrationCode: int
{
    case Ready = 0;
}

#[ORM\MappedSuperclass]
class NativeHydrationParent
{
    #[ORM\Column] private string $inherited;
    public function __construct()
    {
        $this->inherited = 'constructor';
    }
    public function inherited(): string
    {
        return $this->inherited;
    }
}

#[ORM\Entity]
class NativeHydrationRecord extends NativeHydrationParent
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column] public string $name = 'original';
    #[ORM\Column] protected string $protected;
    #[ORM\Column] public int $quantity = 7;
    #[ORM\Column(enumType: HydrationState::class)] public HydrationState $state;
    #[ORM\Column(enumType: HydrationCode::class, nullable: true)] public ?HydrationCode $code = null;
    #[ORM\Column] public string $hooked = 'original' {
        set {
            $this->hooked = $value;
            throw new LogicException('Hydration must bypass set hooks.');
        }
    }
    public string $untouched;
}

#[ORM\Entity]
class NativeHydrationEnumArray
{
    #[ORM\Id, ORM\Column] public int $id;
    /** @var HydrationState[] */
    #[ORM\Column(type: 'json', enumType: HydrationState::class)] public array $states;
}

#[ORM\Embeddable]
class EntityScalarHydrationAddress
{
    public static int $constructors = 0;
    #[ORM\Column] public string $city;
    public function __construct()
    {
        ++self::$constructors;
    }
}
#[ORM\Entity]
class EntityScalarHydrationRecord
{
    public static int $hookCalls = 0;
    #[ORM\Id, ORM\Column] public int $id = 1;
    #[ORM\Column(nullable: true)] private ?string $name = 'original';
    #[ORM\Column] public string $hooked = 'original' {
        get {
            ++self::$hookCalls; return $this->hooked;
        }
        set {
            ++self::$hookCalls; $this->hooked = $value;
        }
    }
    #[ORM\Column(type: 'datetime_immutable')] public readonly DateTimeImmutable $first;
    #[ORM\Column(type: 'datetime_immutable')] public readonly DateTimeImmutable $second;
    #[ORM\Embedded(class: EntityScalarHydrationAddress::class)] public EntityScalarHydrationAddress $address;
    #[ORM\ManyToOne(targetEntity: self::class)] public ?self $parent = null;
    public function __construct(?string $name = 'original')
    {
        $this->name = $name;
        $this->first = new DateTimeImmutable('2026-01-01');
        $this->second = new DateTimeImmutable('2026-01-02');
    }
    public function name(): ?string
    {
        return $this->name;
    }
}

final class EntityHydratorTest extends TestCase
{
    private EntityManager $em;
    protected function setUp(): void
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '18']), $config);
        EntityScalarHydrationRecord::$hookCalls = 0;
        EntityScalarHydrationAddress::$constructors = 0;
    }
    public function testUnmanagedRefreshTargetKeepsSnapshotOwnershipOnTheCanonicalEntity(): void
    {
        $uow = $this->em->getUnitOfWork();
        $canonical = $uow->createEntity(EntityScalarHydrationRecord::class, [
            'id' => 1, 'name' => 'canonical',
            'first' => new DateTimeImmutable('2026-01-01'),
            'second' => new DateTimeImmutable('2026-01-02'),
        ]);
        $target = new EntityScalarHydrationRecord('target');
        $hints = [Query::HINT_REFRESH => true, Query::HINT_REFRESH_ENTITY => $target];

        $result = $uow->createEntity(EntityScalarHydrationRecord::class, [
            'id' => 1, 'name' => 'refreshed',
            'first' => new DateTimeImmutable('2026-01-01'),
            'second' => new DateTimeImmutable('2026-01-02'),
        ], $hints);

        self::assertSame($target, $result);
        self::assertSame('refreshed', $target->name());
        self::assertSame('canonical', $canonical->name());
        self::assertSame($canonical, $uow->tryGetById(['id' => 1], EntityScalarHydrationRecord::class));
        self::assertSame($target->first, $uow->getOriginalEntityData($canonical)['first']);
        self::assertSame('refreshed', $uow->getOriginalEntityData($canonical)['name']);
        self::assertSame([], $uow->getOriginalEntityData($target));
        self::assertSame(1, $uow->size());
        self::assertFalse($this->em->getConnection()->isConnected());
    }

    public function testMappedScalarsUseAccessorsWhileAssociationsAndUnmappedValuesStayUntouched(): void
    {
        $metadata = $this->em->getClassMetadata(EntityScalarHydrationRecord::class);
        $record = new EntityScalarHydrationRecord();
        $parent = new EntityScalarHydrationRecord();
        $record->parent = $parent;
        $data = ['name' => null, 'hooked' => 'database', 'parent' => new EntityScalarHydrationRecord(), 'unknown' => 'ignored'];
        self::assertSame([], iterator_to_array(EntityHydrator::hydrateScalars($metadata, $record, $data)));
        self::assertNull($record->name());
        self::assertSame('database', $metadata->propertyAccessors['hooked']->getValue($record));
        self::assertSame(0, EntityScalarHydrationRecord::$hookCalls);
        self::assertSame($parent, $record->parent);
        self::assertArrayNotHasKey('unknown', (array) $record);
        self::assertSame('database', $data['hooked']);
        self::assertFalse($this->em->getConnection()->isConnected());
        self::assertSame(0, $this->em->getUnitOfWork()->size());
    }
    public function testEmbeddedValuesAreMaterializedWithoutConstructors(): void
    {
        $metadata = $this->em->getClassMetadata(EntityScalarHydrationRecord::class);
        $record = $metadata->newInstance();
        self::assertSame([], iterator_to_array(EntityHydrator::hydrateScalars($metadata, $record, ['address.city' => 'Berlin'])));
        self::assertSame('Berlin', $record->address->city);
        self::assertSame(0, EntityScalarHydrationAddress::$constructors);
    }
    public function testReadonlyEquivalentDatesReturnRetainedObjectsForSnapshots(): void
    {
        $metadata = $this->em->getClassMetadata(EntityScalarHydrationRecord::class);
        $record = new EntityScalarHydrationRecord();
        $date = $record->first;
        $input = new DateTimeImmutable('2026-01-01');
        $corrections = iterator_to_array(EntityHydrator::hydrateScalars($metadata, $record, ['first' => $input]));
        self::assertSame(['first' => $date], $corrections);
        self::assertSame($date, $record->first);
        self::assertNotSame($input, $record->first);
    }
    public function testReadonlySnapshotCorrectionsAreAvailableBeforeALaterAssignmentFails(): void
    {
        $metadata = $this->em->getClassMetadata(EntityScalarHydrationRecord::class);
        $record = new EntityScalarHydrationRecord();
        $first = $record->first;
        $snapshot = [];
        try {
            foreach (EntityHydrator::hydrateScalars($metadata, $record, ['first' => new DateTimeImmutable('2026-01-01'), 'name' => 'written before error', 'second' => new DateTimeImmutable('2026-01-03')]) as $field => $value) {
                $snapshot[$field] = $value;
            }
            self::fail('Changing a readonly date must fail.');
        } catch (LogicException $error) {
            self::assertStringContainsString('readonly property', $error->getMessage());
        }
        self::assertSame(['first' => $first], $snapshot);
        self::assertSame('written before error', $record->name());
        self::assertSame('2026-01-02', $record->second->format('Y-m-d'));
    }
    public function testUninitializedReadonlyValuesCanBeHydrated(): void
    {
        $metadata = $this->em->getClassMetadata(EntityScalarHydrationRecord::class);
        $record = $metadata->newInstance();
        $date = new DateTimeImmutable('2026-01-01');
        self::assertSame(['first' => $date], iterator_to_array(EntityHydrator::hydrateScalars($metadata, $record, ['first' => $date])));
        self::assertSame($date, $record->first);
    }
    public function testEmptyInputDoesNotChangeTheEntity(): void
    {
        $metadata = $this->em->getClassMetadata(EntityScalarHydrationRecord::class);
        $record = new EntityScalarHydrationRecord();
        self::assertSame([], iterator_to_array(EntityHydrator::hydrateScalars($metadata, $record, [])));
        self::assertSame('original', $record->name());
    }

    public function testNativeEligibleFieldsRespectVisibilityAndEnumAccessors(): void
    {
        $metadata = $this->em->getClassMetadata(NativeHydrationRecord::class);
        $record = $metadata->newInstance();
        $data = ['id' => 42, 'inherited' => 'parent', 'protected' => 'protected', 'name' => 'Alice',
            'quantity' => 9, 'state' => 'active', 'code' => 0, 'hooked' => 'raw', 'unknown' => 'ignored'];
        self::assertSame([], iterator_to_array(EntityHydrator::hydrateScalars($metadata, $record, $data)));
        foreach (array_diff_key($data, ['unknown' => true]) as $field => $value) {
            self::assertSame($value, $metadata->propertyAccessors[$field]->getValue($record));
        }
        self::assertSame(HydrationState::Active, $record->state);
        self::assertSame(HydrationCode::Ready, $record->code);
        self::assertSame('parent', $record->inherited());
        self::assertArrayNotHasKey('unknown', (array) $record);
    }

    public function testEnumArraysRetainAccessorConversion(): void
    {
        $metadata = $this->em->getClassMetadata(NativeHydrationEnumArray::class);
        $record = $metadata->newInstance();
        iterator_to_array(EntityHydrator::hydrateScalars($metadata, $record, ['id' => 42, 'states' => ['active']]));
        self::assertSame([HydrationState::Active], $record->states);
        self::assertSame(['active'], $metadata->propertyAccessors['states']->getValue($record));
    }

    public function testLazyHydrationDoesNotRunInitializerIncludingNullToNonNullableField(): void
    {
        $metadata = $this->em->getClassMetadata(NativeHydrationRecord::class);
        $reflection = new ReflectionClass(NativeHydrationRecord::class);
        $record = $reflection->newLazyGhost(static function (): void {
            self::fail('Scalar hydration must not initialize the lazy object.');
        });
        iterator_to_array(EntityHydrator::hydrateScalars($metadata, $record, ['quantity' => null, 'name' => 'Alice', 'inherited' => 'parent']));
        self::assertTrue($reflection->isUninitializedLazyObject($record));
        self::assertTrue($reflection->getProperty('quantity')->isLazy($record));
        self::assertSame('Alice', ((array) $record)['name']);
        self::assertSame('parent', ((array) $record)["\0" . NativeHydrationParent::class . "\0inherited"]);
    }

    public function testTypeFailureKeepsEarlierNativeAssignmentsAndSkipsLaterFields(): void
    {
        $metadata = $this->em->getClassMetadata(NativeHydrationRecord::class);
        $record = $metadata->newInstance();
        try {
            iterator_to_array(EntityHydrator::hydrateScalars($metadata, $record, ['name' => 'before error', 'quantity' => [], 'id' => 42]));
            self::fail('Invalid property type must fail.');
        } catch (TypeError) {
            self::assertSame('before error', $record->name);
            self::assertSame(7, $record->quantity);
            self::assertFalse(isset($record->id));
        }
    }

    public function testNullUnsetsInitializedNonNullableField(): void
    {
        $metadata = $this->em->getClassMetadata(NativeHydrationRecord::class);
        $record = $metadata->newInstance();
        iterator_to_array(EntityHydrator::hydrateScalars($metadata, $record, ['name' => 'before null', 'quantity' => null, 'id' => 42]));
        self::assertFalse(isset($record->quantity));
        self::assertSame('before null', $record->name);
        self::assertSame(42, $record->id);
    }

    public function testInvalidEnumFlushesEarlierNativeBatchBeforeFailure(): void
    {
        $metadata = $this->em->getClassMetadata(NativeHydrationRecord::class);
        $record = $metadata->newInstance();
        try {
            iterator_to_array(EntityHydrator::hydrateScalars($metadata, $record, ['name' => 'before enum', 'state' => 'invalid', 'id' => 42]));
            self::fail('Invalid enum must fail.');
        } catch (ValueError) {
            self::assertSame('before enum', $record->name);
            self::assertFalse(isset($record->state));
            self::assertFalse(isset($record->id));
        }
    }

    public function testWrongEnumBackingTypeRetainsStrictConversionFailure(): void
    {
        $metadata = $this->em->getClassMetadata(NativeHydrationRecord::class);
        $record = $metadata->newInstance();
        try {
            iterator_to_array(EntityHydrator::hydrateScalars($metadata, $record, ['name' => 'before enum', 'state' => 1, 'id' => 42]));
            self::fail('Wrong enum backing type must fail without coercion.');
        } catch (TypeError) {
            self::assertSame('before enum', $record->name);
            self::assertFalse(isset($record->state));
            self::assertFalse(isset($record->id));
        }
    }

    public function testReplacedAccessorInvalidatesNativePlan(): void
    {
        $metadata = $this->em->getClassMetadata(NativeHydrationRecord::class);
        $record = $metadata->newInstance();
        iterator_to_array(EntityHydrator::hydrateScalars($metadata, $record, ['name' => 'warm plan']));
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
                throw new LogicException('Hydration must not inspect custom accessors.');
            }
        };
        iterator_to_array(EntityHydrator::hydrateScalars($metadata, $record, ['name' => 'Alice']));
        self::assertSame('custom:Alice', $record->name);
    }

    public function testNativeWritesPreserveExistingPropertyReferencesWithoutBindingInputReferences(): void
    {
        $metadata = $this->em->getClassMetadata(NativeHydrationRecord::class);
        $record = $metadata->newInstance();
        $quantity = 7;
        $input = 42;
        $record->quantity = & $quantity;
        iterator_to_array(EntityHydrator::hydrateScalars($metadata, $record, ['quantity' => &$input]));
        self::assertSame(42, $quantity);
        $input = 99;
        self::assertSame(42, $record->quantity);
        $quantity = 8;
        self::assertSame(8, $record->quantity);
    }
}
