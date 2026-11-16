<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use LogicException;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Internal\UnitOfWork\EmbeddedHydrator;
use ModernORM\Internal\UnitOfWork\EntityHydrator;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Mapping\PropertyAccessors\PropertyAccessor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use TypeError;

enum EmbeddedBatchState: string
{
    case Active = 'active';
}

#[ORM\Embeddable]
class BatchAddress
{
    public static int $hooks = 0;
    public static int $constructors = 0;
    public string $untouched;
    #[ORM\Column] private string $street;
    #[ORM\Column] public string $city;
    #[ORM\Column] protected string $country;
    #[ORM\Column] public string $zip;
    #[ORM\Column] public string $region;
    #[ORM\Column] public string $district;
    #[ORM\Column] public string $building;
    #[ORM\Column(nullable: true)] public ?string $unit = null;
    #[ORM\Column] public string $hooked = 'original' {
        set {
            ++self::$hooks;
            $this->hooked = $value;
        }
    }
    #[ORM\Column(enumType: EmbeddedBatchState::class)] public EmbeddedBatchState $state;
    #[ORM\Column(type: 'datetime_immutable')] public readonly DateTimeImmutable $created;
    public function __construct()
    {
        $this->street = 'constructor';
        $this->created = new DateTimeImmutable('2026-01-01');
        ++self::$constructors;
    }
    public function street(): string
    {
        return $this->street;
    }
}

class BatchAddressSubclass extends BatchAddress
{
}

#[ORM\Entity]
class BatchOwner
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column] public string $name;
    #[ORM\Column(type: 'datetime_immutable')] public readonly DateTimeImmutable $created;
    #[ORM\Embedded(class: BatchAddress::class)] public BatchAddress $address;
    #[ORM\Embedded(class: BatchAddress::class)] public BatchAddress $other;
    public function __construct()
    {
        $this->created = new DateTimeImmutable('2026-01-01');
    }
}

#[ORM\Entity]
class ReadonlyBatchOwner
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Embedded(class: BatchAddress::class)] public readonly BatchAddress $address;
    public function __construct(BatchAddress $address)
    {
        $this->address = $address;
    }
}

#[ORM\Embeddable]
class BatchContainer
{
    #[ORM\Embedded(class: BatchAddress::class)] public BatchAddress $address;
}

#[ORM\Entity]
class NestedBatchOwner
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Embedded(class: BatchContainer::class)] public BatchContainer $container;
}

final class EmbeddedHydratorTest extends TestCase
{
    private EntityManager $em;
    protected function setUp(): void
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '18']), $config);
        BatchAddress::$hooks = 0;
        BatchAddress::$constructors = 0;
    }
    private function data(string $group = 'address'): array
    {
        $data = [];
        foreach (['street', 'city', 'country', 'zip', 'region', 'district', 'building', 'unit', 'hooked'] as $field) {
            $data[$group . '.' . $field] = $field . '-value';
        }
        return $data;
    }
    private function hydrate(object $owner, array $data): array
    {
        return iterator_to_array(EntityHydrator::hydrateScalars($this->em->getClassMetadata($owner::class), $owner, $data));
    }
    private function owner(): BatchOwner
    {
        return $this->em->getClassMetadata(BatchOwner::class)->newInstance();
    }
    public function testBatchesMappedPrivateProtectedHookedAndNullableChildren(): void
    {
        $metadata = $this->em->getClassMetadata(BatchOwner::class);
        self::assertCount(18, EmbeddedHydrator::plan($metadata));
        $owner = $this->owner();
        $data = $this->data();
        $data['address.unit'] = null;
        self::assertSame([], $this->hydrate($owner, ['id' => 42] + $data + ['unknown' => 'ignored']));
        foreach ($data as $field => $value) {
            self::assertSame($value, $metadata->propertyAccessors[$field]->getValue($owner));
        }
        self::assertSame(0, BatchAddress::$hooks);
        self::assertSame(0, BatchAddress::$constructors);
        self::assertSame('street-value', $owner->address->street());
        self::assertArrayNotHasKey('unknown', (array) $owner);
        self::assertFalse($this->em->getConnection()->isConnected());
    }
    public function testExistingEmbeddedIdentityAndUnassignedFieldsArePreserved(): void
    {
        $owner = $this->owner();
        $this->hydrate($owner, $this->data());
        $address = $owner->address;
        $address->untouched = 'local';
        $this->hydrate($owner, array_map(static fn ($value) => 'updated:' . $value, $this->data()));
        self::assertSame($address, $owner->address);
        self::assertSame('local', $address->untouched);
        self::assertSame('updated:city-value', $address->city);
    }
    public function testSeparateEmbeddedGroupsAndSmallInterleavedSegments(): void
    {
        $owner = $this->owner();
        $this->hydrate($owner, $this->data() + ['name' => 'root'] + $this->data('other'));
        self::assertNotSame($owner->address, $owner->other);
        self::assertSame('city-value', $owner->other->city);
        self::assertSame('root', $owner->name);
        $this->hydrate($owner, ['address.city' => 'first', 'name' => 'middle', 'address.street' => 'last']);
        self::assertSame('first', $owner->address->city);
        self::assertSame('middle', $owner->name);
        self::assertSame('last', $this->em->getClassMetadata(BatchOwner::class)->propertyAccessors['address.street']->getValue($owner));
    }
    public function testNativeFailureAttachesEmbeddedAndKeepsEarlierAssignmentsAndReadonlyCorrection(): void
    {
        $owner = $this->owner();
        $date = new DateTimeImmutable('2026-01-01');
        $this->hydrate($owner, ['created' => $date]);
        $data = $this->data();
        $data['address.zip'] = [];
        $snapshot = [];
        try {
            foreach (EntityHydrator::hydrateScalars(
                $this->em->getClassMetadata(BatchOwner::class),
                $owner,
                ['created' => new DateTimeImmutable('2026-01-01'), 'id' => 42] + $data + ['name' => 'must not write']
            ) as $field => $value) {
                $snapshot[$field] = $value;
            }
            self::fail('Invalid child type must fail.');
        } catch (TypeError) {
            self::assertSame(['created' => $date], $snapshot);
            self::assertSame(42, $owner->id);
            self::assertSame('city-value', $owner->address->city);
            self::assertFalse(isset($owner->address->zip));
            self::assertFalse(isset($owner->address->region));
            self::assertFalse(isset($owner->name));
        }
    }
    public function testRootFailureDoesNotReorderEarlierEmbeddedWritesOrLaterGroups(): void
    {
        $owner = $this->owner();
        try {
            $this->hydrate($owner, $this->data() + ['name' => []] + $this->data('other'));
            self::fail('Invalid root type must fail.');
        } catch (TypeError) {
            self::assertSame('city-value', $owner->address->city);
            self::assertFalse(isset($owner->other));
        }
    }
    public function testNullUnsetsNonNullableChildAndEnumReadonlyKeepAccessors(): void
    {
        $owner = $this->owner();
        $date = new DateTimeImmutable('2026-01-01');
        $this->hydrate($owner, $this->data() + ['address.state' => 'active', 'address.created' => $date]);
        $data = $this->data();
        $data['address.zip'] = null;
        $this->hydrate($owner, $data + ['address.state' => 'active', 'address.created' => new DateTimeImmutable('2026-01-01')]);
        self::assertFalse(isset($owner->address->zip));
        self::assertSame(EmbeddedBatchState::Active, $owner->address->state);
        self::assertSame($date, $owner->address->created);
        self::assertSame(0, BatchAddress::$hooks);
    }
    public function testReadonlyParentAndNestedEmbeddedsRemainOnAccessorPath(): void
    {
        foreach ([ReadonlyBatchOwner::class => 'address', NestedBatchOwner::class => 'container.address'] as $class => $group) {
            $metadata = $this->em->getClassMetadata($class);
            self::assertSame([], EmbeddedHydrator::plan($metadata));
            $owner = $metadata->newInstance();
            $data = $this->data($group);
            self::assertSame([], $this->hydrate($owner, $data));
            foreach ($data as $field => $value) {
                self::assertSame($value, $metadata->propertyAccessors[$field]->getValue($owner));
            }
        }
    }
    public function testLazyEmbeddedAndRuntimeSubclassRetainObjects(): void
    {
        foreach ([BatchAddress::class, BatchAddressSubclass::class] as $class) {
            $reflection = new ReflectionClass($class);
            $address = $class === BatchAddress::class ? $reflection->newLazyGhost(static function (): void {
                self::fail('Embedded hydration must not initialize a lazy object.');
            }) : $reflection->newInstanceWithoutConstructor();
            $owner = $this->owner();
            $owner->address = $address;
            $this->hydrate($owner, $this->data());
            self::assertSame($address, $owner->address);
            self::assertSame('city-value', $address->city);
            if ($class === BatchAddress::class) {
                self::assertTrue($reflection->isUninitializedLazyObject($address));
            }
        }
    }
    public function testCustomAccessorReplacementInvalidatesCachedPlan(): void
    {
        $metadata = $this->em->getClassMetadata(BatchOwner::class);
        self::assertArrayHasKey('address.street', EmbeddedHydrator::plan($metadata));
        $metadata->propertyAccessors['address.street'] = new class ($metadata->propertyAccessors['address.street']) implements PropertyAccessor {
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
        self::assertArrayNotHasKey('address.street', EmbeddedHydrator::plan($metadata));
        $owner = $this->owner();
        $this->hydrate($owner, $this->data());
        self::assertSame('custom:street-value', $metadata->propertyAccessors['address.street']->getValue($owner));
        self::assertSame('city-value', $owner->address->city);
    }
    public function testStringCoercionCanReplaceEmbeddedWithoutReorderingLaterWrites(): void
    {
        $owner = $this->owner();
        $old = (new ReflectionClass(BatchAddress::class))->newInstanceWithoutConstructor();
        $replacement = (new ReflectionClass(BatchAddress::class))->newInstanceWithoutConstructor();
        $owner->address = $old;
        $data = $this->data();
        $data['address.hooked'] = new class ($owner, $replacement) {
            public function __construct(private BatchOwner $owner, private BatchAddress $replacement)
            {
            }
            public function __toString(): string
            {
                $this->owner->address = $this->replacement;
                return 'coerced';
            }
        };
        $this->hydrate($owner, $data + ['address.state' => 'active']);
        self::assertSame('city-value', $old->city);
        self::assertSame('coerced', $old->hooked);
        self::assertSame($replacement, $owner->address);
        self::assertSame(EmbeddedBatchState::Active, $replacement->state);
        self::assertFalse(isset($replacement->city));
    }

    public function testInputReferencesObserveEarlierWritesInLargeAndSmallGroups(): void
    {
        $owner = $this->owner();
        $this->hydrate($owner, $this->data());
        $owner->address->city = 'old-city';
        $data = $this->data();
        $data['address.hooked'] = & $owner->address->city;
        $this->hydrate($owner, $data);
        self::assertSame('city-value', $owner->address->hooked);
        $owner->address->city = 'later';
        self::assertSame('city-value', $this->em->getClassMetadata(BatchOwner::class)->propertyAccessors['address.hooked']->getValue($owner));
        $small = ['address.city' => 'first', 'address.zip' => &$owner->address->city];
        $this->hydrate($owner, $small);
        self::assertSame('first', $owner->address->zip);
    }
}
