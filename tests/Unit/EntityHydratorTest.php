<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use LogicException;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Internal\UnitOfWork\EntityHydrator;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use PHPUnit\Framework\TestCase;

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
}
