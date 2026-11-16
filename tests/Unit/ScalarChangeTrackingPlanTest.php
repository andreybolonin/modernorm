<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Events;
use ModernORM\Event\PreFlushEventArgs;
use ModernORM\Internal\UnitOfWork\ChangeSetComputer;
use ModernORM\Internal\UnitOfWork\ScalarChangeTrackingPlan;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use PHPUnit\Framework\TestCase;

#[ORM\Entity]
class ScalarTrackedRecord
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue] public ?int $id = 1;
    #[ORM\Column] private string $name = 'before';
    #[ORM\Column(nullable: true)] protected ?string $note = null;
    #[ORM\Column] public bool $enabled = false;
    #[ORM\Column] public float $amount = 1.25;
    #[ORM\Column(updatable: false)] public string $insertOnly = 'fixed';
    #[ORM\Column, ORM\Version] public int $version = 1;
    public function rename(string $name): void
    {
        $this->name = $name;
    }
}

#[ORM\Entity]
class HookedTrackedRecord
{
    #[ORM\Id, ORM\Column] public int $id = 1;
    #[ORM\Column] public string $name = 'before' {
        set {
            $this->name = strtoupper($value);
        }
    }
}

final class ScalarChangeTrackingPlanTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '18']), $config);
    }

    private function actual(ORM\ClassMetadata $metadata, object $entity): array
    {
        $actual = [];
        foreach ($metadata->propertyAccessors as $field => $accessor) {
            $value = $accessor->getValue($entity);
            if ((! $metadata->isIdentifier($field) || ! $metadata->isIdGeneratorIdentity()) && $field !== $metadata->versionField) {
                $actual[$field] = $value;
            }
        }
        return $actual;
    }

    public function testManagedTransitionsMatchGenericComparisonAndSnapshotShape(): void
    {
        $entity = new ScalarTrackedRecord();
        $metadata = $this->em->getClassMetadata($entity::class);
        self::assertNotNull(ScalarChangeTrackingPlan::forMetadata($metadata));
        $original = array_reverse($this->actual($metadata, $entity), true) + ['id' => 1, 'version' => 1, 'extra' => 'discard'];
        $uow = $this->em->getUnitOfWork();
        $uow->registerManaged($entity, ['id' => 1], $original);
        $uow->computeChangeSets();
        self::assertSame([], $uow->getEntityChangeSet($entity));
        self::assertSame($original, $uow->getOriginalEntityData($entity));
        foreach (['first', 'second'] as $name) {
            $entity->rename($name);
            $entity->enabled = true;
            $entity->insertOnly = 'local';
            $entity->version = 2;
            $actual = $this->actual($metadata, $entity);
            $expected = ChangeSetComputer::compare($metadata, $original, $actual);
            $uow->computeChangeSets();
            self::assertSame($expected, $uow->getEntityChangeSet($entity));
            self::assertSame($actual, $uow->getOriginalEntityData($entity));
            self::assertSame([$entity], array_values($uow->getScheduledEntityUpdates()));
            $uow->computeChangeSets();
            self::assertSame($expected, $uow->getEntityChangeSet($entity));
            $original = $actual;
        }
        self::assertFalse($this->em->getConnection()->isConnected());
    }

    public function testMissingBaselinesAndInsertOnlyChangesDoNotScheduleUpdates(): void
    {
        $entity = new ScalarTrackedRecord();
        $metadata = $this->em->getClassMetadata($entity::class);
        $uow = $this->em->getUnitOfWork();
        $uow->registerManaged($entity, ['id' => 1], ['insertOnly' => 'database']);
        $entity->rename('missing baseline');
        $uow->computeChangeSets();
        self::assertSame([], $uow->getEntityChangeSet($entity));
        self::assertSame(['insertOnly' => 'database'], $uow->getOriginalEntityData($entity));
        self::assertFalse($uow->isScheduledForUpdate($entity));
    }

    public function testPreFlushMutationIsObservedAndReadOnlyEntityIsSkipped(): void
    {
        $entity = new ScalarTrackedRecord();
        $metadata = $this->em->getClassMetadata($entity::class);
        $metadata->addEntityListener(Events::preFlush, ScalarTrackedListener::class, 'preFlush');
        $uow = $this->em->getUnitOfWork();
        $uow->registerManaged($entity, ['id' => 1], $this->actual($metadata, $entity));
        $uow->computeChangeSets();
        self::assertSame(['name' => ['before', 'listener']], $uow->getEntityChangeSet($entity));
        $uow->markReadOnly($entity);
        $entity->rename('ignored');
        $uow->computeChangeSets();
        self::assertSame(['name' => ['before', 'listener']], $uow->getEntityChangeSet($entity));
    }

    public function testMetadataChangesInvalidateAcceptedAndRejectedPlans(): void
    {
        $metadata = $this->em->getClassMetadata(ScalarTrackedRecord::class);
        $plan = ScalarChangeTrackingPlan::forMetadata($metadata);
        self::assertNotNull($plan);
        self::assertSame($plan, ScalarChangeTrackingPlan::forMetadata($metadata));
        $metadata->changeTrackingPolicy = ORM\ClassMetadata::CHANGETRACKING_DEFERRED_EXPLICIT;
        self::assertNull(ScalarChangeTrackingPlan::forMetadata($metadata));
        self::assertNull(ScalarChangeTrackingPlan::forMetadata($metadata));
        $metadata->changeTrackingPolicy = ORM\ClassMetadata::CHANGETRACKING_DEFERRED_IMPLICIT;
        $next = ScalarChangeTrackingPlan::forMetadata($metadata);
        self::assertNotNull($next);
        self::assertNotSame($plan, $next);
        $metadata->propertyAccessors['name'] = $metadata->propertyAccessors['insertOnly'];
        self::assertNull(ScalarChangeTrackingPlan::forMetadata($metadata));
    }

    public function testHooksAndUninitializedLazyObjectsUseCompatibilityPath(): void
    {
        self::assertNull(ScalarChangeTrackingPlan::forMetadata($this->em->getClassMetadata(HookedTrackedRecord::class)));
        $metadata = $this->em->getClassMetadata(ScalarTrackedRecord::class);
        $plan = ScalarChangeTrackingPlan::forMetadata($metadata);
        self::assertNotNull($plan);
        $initializations = 0;
        $ghost = $metadata->reflClass->newLazyGhost(static function () use (&$initializations): void {
            ++$initializations;
        });
        self::assertNull($plan->compare($metadata, $ghost, []));
        self::assertSame(0, $initializations);
        self::assertNull($plan->compare($metadata, new HookedTrackedRecord(), []));
    }

    public function testStandardWrapperAroundCustomAccessorUsesCompatibilityPath(): void
    {
        $metadata = $this->em->getClassMetadata(ScalarTrackedRecord::class);
        self::assertNotNull(ScalarChangeTrackingPlan::forMetadata($metadata));
        $property = $metadata->propertyAccessors['name']->getUnderlyingReflector();
        $custom = new class ($property) implements ORM\PropertyAccessors\PropertyAccessor {
            public function __construct(private \ReflectionProperty $property)
            {
            }
            public function getValue(object $object): mixed
            {
                return 'custom';
            }
            public function setValue(object $object, mixed $value): void
            {
            }
            public function getUnderlyingReflector(): \ReflectionProperty
            {
                return $this->property;
            }
        };
        $metadata->propertyAccessors['name'] = new ORM\PropertyAccessors\TypedNoDefaultPropertyAccessor($custom, $property);
        self::assertNull(ScalarChangeTrackingPlan::forMetadata($metadata));
        $entity = new ScalarTrackedRecord();
        $uow = $this->em->getUnitOfWork();
        $uow->registerManaged($entity, ['id' => 1], ['name' => 'before']);
        $uow->computeChangeSets();
        self::assertSame(['name' => ['before', 'custom']], $uow->getEntityChangeSet($entity));
    }

    public function testCachedPlansDoNotKeepMetadataAlive(): void
    {
        $metadata = clone $this->em->getClassMetadata(ScalarTrackedRecord::class);
        $reference = \WeakReference::create($metadata);
        $plan = ScalarChangeTrackingPlan::forMetadata($metadata);
        self::assertNotNull($plan);
        unset($metadata);
        gc_collect_cycles();
        self::assertNull($reference->get());
    }

    public function testAssociationsEmbeddablesAndInheritanceRejectThePlan(): void
    {
        $metadata = clone $this->em->getClassMetadata(ScalarTrackedRecord::class);
        $metadata->mapManyToOne(['fieldName' => 'parent', 'targetEntity' => ScalarTrackedRecord::class]);
        self::assertNull(ScalarChangeTrackingPlan::forMetadata($metadata));
        $metadata = clone $this->em->getClassMetadata(ScalarTrackedRecord::class);
        $metadata->mapEmbedded(['fieldName' => 'embedded', 'class' => ScalarTrackedRecord::class]);
        self::assertNull(ScalarChangeTrackingPlan::forMetadata($metadata));
        $metadata = clone $this->em->getClassMetadata(ScalarTrackedRecord::class);
        $metadata->inheritanceType = ORM\ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE;
        self::assertNull(ScalarChangeTrackingPlan::forMetadata($metadata));
    }

    public function testNonScalarOriginalRequestsFallbackWithoutChangingState(): void
    {
        $metadata = $this->em->getClassMetadata(ScalarTrackedRecord::class);
        $plan = ScalarChangeTrackingPlan::forMetadata($metadata);
        self::assertNotNull($plan);
        $original = ['name' => new \stdClass()];
        self::assertNull($plan->compare($metadata, new ScalarTrackedRecord(), $original));
        self::assertInstanceOf(\stdClass::class, $original['name']);
    }
}

class ScalarTrackedListener
{
    public function preFlush(ScalarTrackedRecord $entity, PreFlushEventArgs $args): void
    {
        $entity->rename('listener');
    }
}
