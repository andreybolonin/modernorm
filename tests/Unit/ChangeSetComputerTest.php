<?php

declare(strict_types=1);

namespace ModernORM\Tests\Unit;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\DriverManager;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Internal\UnitOfWork\ChangeSetComputer;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

enum ComputerState: string
{
    case Ready = 'ready';
    case Done = 'done';
}
class ComputerAddress
{
    public string $city = 'Paris';
}
#[ORM\Entity]
class ComputerRecord
{
    #[ORM\Id, ORM\Column] public int $id = 1;
    #[ORM\Column(nullable: true)] public ?string $name = 'value';
    #[ORM\Column(updatable: false)] public string $insertOnly = 'insert';
}

final class ChangeSetComputerTest extends TestCase
{
    private function metadata(): ORM\ClassMetadata
    {
        $metadata = new ORM\ClassMetadata(ComputerRecord::class);
        foreach (['name', 'other', 'count', 'date'] as $field) {
            $metadata->mapField(['fieldName' => $field, 'type' => 'string']);
        }
        $metadata->mapField(['fieldName' => 'state', 'type' => 'string', 'enumType' => ComputerState::class]);
        $metadata->mapField(['fieldName' => 'states', 'type' => 'json', 'enumType' => ComputerState::class]);
        $metadata->mapField(['fieldName' => 'insertOnly', 'type' => 'string', 'notUpdatable' => true]);
        $metadata->mapField(['fieldName' => 'generated', 'type' => 'string', 'notUpdatable' => true, 'notInsertable' => true]);
        $metadata->mapField(['fieldName' => 'updateOnly', 'type' => 'string', 'notInsertable' => true]);
        $metadata->mapEmbedded(['fieldName' => 'address', 'class' => ComputerAddress::class]);
        $metadata->mapField(['fieldName' => 'address.city', 'columnName' => 'address_city', 'type' => 'string']);
        $metadata->mapManyToOne(['fieldName' => 'parent', 'targetEntity' => ComputerRecord::class]);
        $metadata->mapManyToMany(['fieldName' => 'children', 'targetEntity' => ComputerRecord::class]);
        return $metadata;
    }
    public static function scalarSnapshots(): iterable
    {
        yield 'unchanged' => [['name' => 'same'], ['name' => 'same'], []];
        yield 'loaded null is a baseline' => [['name' => null], ['name' => 'new'], ['name' => [null, 'new']]];
        yield 'cleared value' => [['name' => 'old'], ['name' => null], ['name' => ['old', null]]];
        yield 'strict types' => [['count' => 1], ['count' => '1'], ['count' => [1, '1']]];
        yield 'missing baseline skipped' => [[], ['name' => 'new'], []];
        yield 'missing actual skipped' => [['name' => 'old'], [], []];
        yield 'native enum unchanged' => [['state' => ComputerState::Ready], ['state' => 'ready'], []];
        yield 'native enum changed' => [['state' => ComputerState::Ready], ['state' => 'done'], ['state' => ['ready', 'done']]];
        yield 'keyed enum array' => [['states' => ['key' => ComputerState::Ready, 7 => null]], ['states' => ['key' => 'ready', 7 => null]], []];
        yield 'update-only field stays writable' => [['updateOnly' => 'old'], ['updateOnly' => 'new'], ['updateOnly' => ['old', 'new']]];
    }
    #[DataProvider('scalarSnapshots')]
    public function testScalarSnapshotContract(array $original, array $actual, array $expected): void
    {
        $metadata = $this->metadata();
        $before = serialize($metadata);
        self::assertSame($expected, ChangeSetComputer::compare($metadata, $original, $actual));
        self::assertSame($before, serialize($metadata));
    }
    public function testUpdatePermissionsDifferFromPreparedInsertComparison(): void
    {
        $metadata = $this->metadata();
        $original = ['insertOnly' => 'old', 'generated' => 'database', 'name' => 'old'];
        $actual = ['generated' => 'local', 'name' => 'new', 'insertOnly' => 'new'];
        self::assertSame(['name' => ['old', 'new']], ChangeSetComputer::compare($metadata, $original, $actual));
        self::assertSame(['generated' => ['database', 'local'], 'name' => ['old', 'new'], 'insertOnly' => ['old', 'new']], ChangeSetComputer::compareForInsert($metadata, $original, $actual));
    }
    public function testAssociationsAreLeftToCoordinatorAndOutputKeepsActualFieldOrder(): void
    {
        $metadata = $this->metadata();
        $oldParent = new ComputerRecord();
        $parent = new ComputerRecord();
        $original = ['name' => 'old', 'other' => 'old', 'parent' => $oldParent, 'children' => new ArrayCollection()];
        $actual = ['other' => 'new', 'parent' => $parent, 'name' => 'new', 'children' => new ArrayCollection([$parent])];
        self::assertSame(['other' => ['old', 'new'], 'name' => ['old', 'new']], ChangeSetComputer::compare($metadata, $original, $actual));
        self::assertSame($parent, $actual['parent']);
        self::assertSame($oldParent, $original['parent']);
    }
    public function testEmbeddedContainersRetainIdentityComparisonAlongsideFlattenedValues(): void
    {
        $metadata = $this->metadata();
        $address = new ComputerAddress();
        $original = ['address' => $address, 'address.city' => 'Paris'];
        $address->city = 'Berlin';
        self::assertSame(['address.city' => ['Paris', 'Berlin']], ChangeSetComputer::compare($metadata, $original, ['address' => $address, 'address.city' => 'Berlin']));
        $replacement = clone $address;
        self::assertSame(['address' => [$address, $replacement], 'address.city' => ['Paris', 'Berlin']], ChangeSetComputer::compare($metadata, $original, ['address' => $replacement, 'address.city' => 'Berlin']));
    }
    public function testMutableObjectsAndEnumsDoNotMutateInputSnapshots(): void
    {
        $metadata = $this->metadata();
        $date = new DateTime('2026-01-01');
        $original = ['date' => $date, 'state' => ComputerState::Ready];
        $date->modify('+1 day');
        $actual = ['date' => $date, 'state' => 'done'];
        self::assertSame(['state' => ['ready', 'done']], ChangeSetComputer::compare($metadata, $original, $actual));
        self::assertSame(ComputerState::Ready, $original['state']);
        $replacement = clone $date;
        $actual['date'] = $replacement;
        self::assertSame(['date' => [$date, $replacement], 'state' => ['ready', 'done']], ChangeSetComputer::compare($metadata, $original, $actual));
    }
    public function testCoordinatorPreservesMissingBaselineDifferenceWithoutSql(): void
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '18']), $config);
        $record = new ComputerRecord();
        $class = $em->getClassMetadata(ComputerRecord::class);
        $uow = $em->getUnitOfWork();
        // This is a complete PHP entity with an intentionally incomplete internal baseline.
        $uow->registerManaged($record, ['id' => 1], ['id' => 1]);
        $uow->computeChangeSet($class, $record);
        self::assertSame([], $uow->getEntityChangeSet($record));
        self::assertFalse($uow->isScheduledForUpdate($record));
        $uow->recomputeSingleEntityChangeSet($class, $record);
        self::assertSame(['name' => [null, 'value']], $uow->getEntityChangeSet($record));
        self::assertTrue($uow->isScheduledForUpdate($record));
        self::assertFalse($em->getConnection()->isConnected());
    }
}
