<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\EntityManagerInterface;
use ModernORM\Id\AbstractIdGenerator;
use ModernORM\Internal\UnitOfWork\EntityStateManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Persisters\Entity\EntityPersister;
use ModernORM\UnitOfWork;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[ORM\Entity]
class StateAssignedRecord
{
    #[ORM\Id, ORM\Column] public ?int $id = null;
}

#[ORM\Entity]
class StateVersionedRecord
{
    #[ORM\Id, ORM\Column] public ?int $id = null;
    #[ORM\Column, ORM\Version] public ?int $version = null;
}

enum StateIdentifier: string
{
    case Key = 'key';
}
#[ORM\Entity]
class StateEnumRecord
{
    #[ORM\Id, ORM\Column(enumType: StateIdentifier::class)] public StateIdentifier $id = StateIdentifier::Key;
}

class StateObservingUnitOfWork extends UnitOfWork
{
    public array $trace = [];
    public array $observedIds = [];
    public EntityPersister $testPersister;
    public bool $databaseExists = false;
    public function tryGetById(mixed $id, string $rootClassName): object|false
    {
        $this->trace[] = 'identity';
        $this->observedIds[] = $id;
        return parent::tryGetById($id, $rootClassName);
    }
    public function getEntityPersister(string $entityName): EntityPersister
    {
        $this->trace[] = 'persister';
        return $this->testPersister;
    }
}

final class EntityStateDetectionTest extends TestCase
{
    private EntityManager $em;
    private StateObservingUnitOfWork $uow;
    protected function setUp(): void
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '18']), $config);
        $this->uow = new StateObservingUnitOfWork($this->em);
        $persister = $this->createMock(EntityPersister::class);
        $persister->method('exists')->willReturnCallback(function (object $entity): bool {
            $this->uow->trace[] = 'exists';
            return $this->uow->databaseExists;
        });
        $this->uow->testPersister = $persister;
    }

    public static function decisions(): array
    {
        return [
            'falsy version precedes generator and lookup' => [false, true, true, UnitOfWork::STATE_NEW, 0],
            'truthy version' => [true, false, false, UnitOfWork::STATE_DETACHED, 0],
            'post insert' => [null, true, false, UnitOfWork::STATE_DETACHED, 0],
            'absent' => [null, false, false, UnitOfWork::STATE_NEW, 1],
            'exists' => [null, false, true, UnitOfWork::STATE_DETACHED, 1],
        ];
    }
    #[DataProvider('decisions')]
    public function testManagerResolvesFactsWithoutCachingUnknownState(?bool $version, bool $postInsert, bool $exists, int $expected, int $calls): void
    {
        $states = new EntityStateManager(static fn (object $entity): bool => true);
        $observations = 0;
        $result = $states->resolveIdentifiedState($version, $postInsert, static function () use (&$observations, $exists): bool {
            ++$observations;
            return $exists;
        });
        self::assertSame($expected, $result);
        self::assertSame($calls, $observations);
        self::assertSame([], $states->states);
        self::assertSame([], $states->identifiers);
        self::assertSame([], $states->snapshots);
    }

    public function testCachedAndAssumedStatesDoNotReadMetadata(): void
    {
        $entity = new stdClass(); // Deliberately unmapped: metadata access would fail.
        $states = (new \ReflectionProperty(UnitOfWork::class, 'entityStateManager'))->getValue($this->uow);
        $states->states[spl_object_id($entity)] = UnitOfWork::STATE_REMOVED;
        self::assertSame(UnitOfWork::STATE_REMOVED, $this->uow->getEntityState($entity, UnitOfWork::STATE_NEW));
        self::assertSame(UnitOfWork::STATE_NEW, $this->uow->getEntityState(new stdClass(), UnitOfWork::STATE_NEW));
        self::assertSame([], $this->uow->trace);
        self::assertFalse($this->em->getConnection()->isConnected());
    }

    public static function versions(): array
    {
        return ['null' => [null, UnitOfWork::STATE_NEW], 'zero' => [0, UnitOfWork::STATE_NEW], 'positive' => [7, UnitOfWork::STATE_DETACHED]];
    }
    #[DataProvider('versions')]
    public function testNaturalVersionShortCircuitDoesNotConsultGeneratorOrLookup(?int $version, int $expected): void
    {
        $entity = new StateVersionedRecord();
        $entity->id = 7;
        $entity->version = $version;
        $metadata = $this->em->getClassMetadata($entity::class);
        $metadata->idGenerator = new class () extends AbstractIdGenerator {
            public function generateId(EntityManagerInterface $em, ?object $entity): mixed
            {
                throw new RuntimeException('Unexpected generation');
            }
            public function isPostInsertGenerator(): bool
            {
                throw new RuntimeException('Unexpected generator inspection');
            }
        };
        self::assertSame($expected, $this->uow->getEntityState($entity));
        self::assertSame([], $this->uow->trace);
        self::assertFalse($this->em->getConnection()->isConnected());
    }

    public function testEmptyIdDoesNotReadVersionOrInspectGenerator(): void
    {
        $entity = new StateVersionedRecord();
        $metadata = $this->em->getClassMetadata($entity::class);
        $accessor = $this->createMock(ORM\PropertyAccessors\PropertyAccessor::class);
        $accessor->expects(self::never())->method('getValue');
        $metadata->propertyAccessors['version'] = $accessor;
        self::assertSame(UnitOfWork::STATE_NEW, $this->uow->getEntityState($entity));
        self::assertSame([], $this->uow->trace);
    }

    public static function generators(): array
    {
        return ['pre insert' => [false], 'post insert' => [true]];
    }
    #[DataProvider('generators')]
    public function testGeneratedIdUsesLookupOnlyForPreInsert(bool $postInsert): void
    {
        $entity = new StateAssignedRecord();
        $entity->id = 7;
        $metadata = $this->em->getClassMetadata($entity::class);
        $metadata->generatorType = ORM\ClassMetadata::GENERATOR_TYPE_CUSTOM;
        $metadata->idGenerator = new class ($postInsert, $this->uow) extends AbstractIdGenerator {
            public function __construct(private bool $postInsert, private StateObservingUnitOfWork $uow)
            {
            }
            public function generateId(EntityManagerInterface $em, ?object $entity): mixed
            {
                return 7;
            }
            public function isPostInsertGenerator(): bool
            {
                $this->uow->trace[] = 'generator';
                return $this->postInsert;
            }
        };
        self::assertSame($postInsert ? UnitOfWork::STATE_DETACHED : UnitOfWork::STATE_NEW, $this->uow->getEntityState($entity));
        self::assertSame($postInsert ? ['generator'] : ['generator', 'identity', 'persister', 'exists'], $this->uow->trace);
        self::assertFalse($this->em->getConnection()->isConnected());
    }

    public function testEnumIdentityIsNormalizedBeforeMapHitAndBypassesPersister(): void
    {
        $canonical = new StateEnumRecord();
        $this->uow->registerManaged($canonical, ['id' => 'key'], []);
        self::assertSame(UnitOfWork::STATE_DETACHED, $this->uow->getEntityState(new StateEnumRecord()));
        self::assertSame([['id' => 'key']], $this->uow->observedIds);
        self::assertSame(['identity'], $this->uow->trace);
    }

    public function testLookupFailurePropagatesWithoutInstallingState(): void
    {
        $entity = new StateAssignedRecord();
        $entity->id = 7;
        $error = new RuntimeException('lookup failed');
        $originalPersister = $this->uow->testPersister;
        $persister = $this->createMock(EntityPersister::class);
        $persister->expects(self::once())->method('exists')->with($entity)->willThrowException($error);
        $this->uow->testPersister = $persister;
        try {
            $this->uow->getEntityState($entity);
            self::fail('Expected lookup failure');
        } catch (RuntimeException $caught) {
            self::assertSame($error, $caught);
        }
        self::assertSame(['identity', 'persister'], $this->uow->trace);
        $states = (new \ReflectionProperty(UnitOfWork::class, 'entityStateManager'))->getValue($this->uow);
        self::assertArrayNotHasKey(spl_object_id($entity), $states->states);
        $this->uow->testPersister = $originalPersister;
        self::assertSame(UnitOfWork::STATE_NEW, $this->uow->getEntityState($entity));
    }

    public function testExistenceResultsAreNotCached(): void
    {
        $entity = new StateAssignedRecord();
        $entity->id = 7;
        self::assertSame(UnitOfWork::STATE_NEW, $this->uow->getEntityState($entity));
        $this->uow->databaseExists = true;
        self::assertSame(UnitOfWork::STATE_DETACHED, $this->uow->getEntityState($entity));
        self::assertSame(['identity', 'persister', 'exists', 'identity', 'persister', 'exists'], $this->uow->trace);
        self::assertFalse($this->em->getConnection()->isConnected());
    }
}
