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

use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\ClassMetadata;
use ModernORM\Mapping\Driver\AttributeDriver;
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
}
