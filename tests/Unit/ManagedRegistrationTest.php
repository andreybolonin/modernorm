<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Exception\EntityIdentityCollisionException;
use ModernORM\Exception\ORMInvalidArgumentException;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use LogicException;

#[ORM\Entity]
class RegistrationGeneratedRecord
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue] public ?int $id = null;
    #[ORM\Column] public string $name = 'original';
}

#[ORM\Entity]
class RegistrationReadonlyRecord
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue] public readonly int $id;
    public function __construct(int $id)
    {
        $this->id = $id;
    }
}

final class ManagedRegistrationTest extends TestCase
{
    private EntityManager $em;
    protected function setUp(): void
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '18']), $config);
    }
    public function testGeneratedIdIsConvertedAssignedAndMergedIntoExistingSnapshot(): void
    {
        $entity = new RegistrationGeneratedRecord();
        $uow = $this->em->getUnitOfWork();
        $uow->setOriginalEntityData($entity, ['name' => 'pending', 'marker' => 'keep']);
        $uow->assignPostInsertId($entity, '7');
        self::assertSame(7, $entity->id);
        self::assertSame(['id' => 7], $uow->getEntityIdentifier($entity));
        self::assertSame(['name' => 'pending', 'marker' => 'keep', 'id' => 7], $uow->getOriginalEntityData($entity));
        self::assertSame(UnitOfWork::STATE_MANAGED, $uow->getEntityState($entity));
        self::assertSame($entity, $uow->tryGetById(['id' => 7], RegistrationGeneratedRecord::class));
        self::assertFalse($this->em->getConnection()->isConnected());
    }
    public function testReadonlyPropertyFailureOccursBeforeStateRegistration(): void
    {
        $entity = new RegistrationReadonlyRecord(5);
        $uow = $this->em->getUnitOfWork();
        $uow->registerManaged($entity, ['id' => 5], ['id' => 5, 'marker' => 'before']);
        try {
            $uow->assignPostInsertId($entity, 6);
            self::fail('Replacing an initialized readonly ID must fail.');
        } catch (LogicException) {
            self::assertSame(5, $entity->id);
            self::assertSame(['id' => 5], $uow->getEntityIdentifier($entity));
            self::assertSame(['id' => 5, 'marker' => 'before'], $uow->getOriginalEntityData($entity));
            self::assertSame($entity, $uow->tryGetById(['id' => 5], RegistrationReadonlyRecord::class));
            self::assertFalse($uow->tryGetById(['id' => 6], RegistrationReadonlyRecord::class));
        }
    }
    public function testGeneratedIdCollisionKeepsAssignmentAndCanonicalIdentity(): void
    {
        $uow = $this->em->getUnitOfWork();
        $first = new RegistrationGeneratedRecord();
        $copy = new RegistrationGeneratedRecord();
        $uow->assignPostInsertId($first, 7);
        $uow->setOriginalEntityData($copy, ['marker' => 'keep']);
        try {
            $uow->assignPostInsertId($copy, '7');
            self::fail('The generated ID must collide with the canonical object.');
        } catch (EntityIdentityCollisionException) {
            self::assertSame(7, $copy->id);
            self::assertSame(['id' => 7], $uow->getEntityIdentifier($copy));
            self::assertSame(['marker' => 'keep', 'id' => 7], $uow->getOriginalEntityData($copy));
            self::assertFalse($uow->isInIdentityMap($copy));
            self::assertSame($first, $uow->tryGetById(['id' => 7], RegistrationGeneratedRecord::class));
        }
    }
    public function testInvalidIdentityFailsAfterRecordingManagedState(): void
    {
        $entity = new RegistrationGeneratedRecord();
        $uow = $this->em->getUnitOfWork();
        try {
            $uow->registerManaged($entity, [], ['marker' => 'recorded']);
            self::fail('An empty identifier must fail validation.');
        } catch (ORMInvalidArgumentException) {
            self::assertSame([], $uow->getEntityIdentifier($entity));
            self::assertSame(UnitOfWork::STATE_MANAGED, $uow->getEntityState($entity));
            self::assertSame(['marker' => 'recorded'], $uow->getOriginalEntityData($entity));
            self::assertFalse($uow->isInIdentityMap($entity));
        }
        $uow->clear();
        self::assertSame([], $uow->getOriginalEntityData($entity));
        self::assertSame([], $uow->getIdentityMap());
    }

    public function testDetachCancelsPrimaryWritesAndForgetsStateWithoutResettingId(): void
    {
        $entity = new RegistrationGeneratedRecord();
        $other = new RegistrationGeneratedRecord();
        $uow = $this->em->getUnitOfWork();
        $uow->assignPostInsertId($entity, 7);
        $uow->assignPostInsertId($other, 8);
        $uow->setOriginalEntityData($entity, ['id' => 7, 'name' => 'before']);
        $uow->scheduleForUpdate($entity);
        $uow->detach($entity);
        self::assertSame(7, $entity->id);
        self::assertSame([], $uow->getOriginalEntityData($entity));
        self::assertSame([], $uow->getScheduledEntityUpdates());
        self::assertSame(UnitOfWork::STATE_DETACHED, $uow->getEntityState($entity));
        self::assertFalse($uow->isInIdentityMap($entity));
        self::assertSame($other, $uow->tryGetById(['id' => 8], RegistrationGeneratedRecord::class));
        $uow->detach($entity);
        self::assertSame(1, $uow->size());
        self::assertFalse($this->em->getConnection()->isConnected());
    }

}
