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

namespace ModernORM\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Query\ResultSetMapping;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use WeakReference;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_partial_tracking')]
final class PartialTrackingRecord
{
    #[ORM\Id, ORM\Column]
    public int $id = 1;

    #[ORM\Column(nullable: true)]
    public ?string $title = null;

    #[ORM\Column]
    public string $body = 'stored body';

    #[ORM\Column(type: 'datetime_immutable')]
    public DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable('2026-01-01 12:00:00');
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_partial_details')]
final class PartialTrackingDetail
{
    #[ORM\Id, ORM\Column]
    public int $id = 1;

    #[ORM\OneToOne(targetEntity: PartialTrackingOwner::class, mappedBy: 'detail')]
    public ?PartialTrackingOwner $owner = null;
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_partial_owners')]
final class PartialTrackingOwner
{
    #[ORM\Id, ORM\Column]
    public int $id = 1;

    #[ORM\OneToOne(targetEntity: PartialTrackingDetail::class, inversedBy: 'owner')]
    public ?PartialTrackingDetail $detail = null;
}

final class PartialLoadTrackingTest extends TestCase
{
    private EntityManager $em;
    private Statistics $statistics;

    protected function setUp(): void
    {
        $this->statistics = new Statistics(enabled: true);
        $dbal = new Configuration();
        $dbal->setMiddlewares([new Middleware($this->statistics)]);
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $dbal), ORMSetup::createAttributeMetadataConfig([__DIR__], true, cache: new ArrayAdapter()));
        $metadata = array_map($this->em->getClassMetadata(...), [PartialTrackingRecord::class, PartialTrackingDetail::class, PartialTrackingOwner::class]);
        $schema = new SchemaTool($this->em);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $connection->executeStatement('DROP TABLE IF EXISTS modernorm_test_partial_owners, modernorm_test_partial_details, modernorm_test_partial_tracking');
        $connection->close();
    }

    private function partial(?string $title = null, bool $withDate = false): PartialTrackingRecord
    {
        $record = new PartialTrackingRecord();
        $record->title = $title;
        $this->em->persist($record);
        $this->em->flush();
        $this->em->clear();
        $mapping = new ResultSetMapping();
        $mapping->addEntityResult(PartialTrackingRecord::class, 'r');
        $mapping->addFieldResult('r', 'id', 'id');
        $mapping->addFieldResult('r', 'title', 'title');
        $mapping->partialAliases['r'] = true;
        $columns = 'id, title';
        if ($withDate) {
            $mapping->addFieldResult('r', 'created_at', 'createdAt');
            $columns .= ', createdAt AS created_at';
        }
        $result = $this->em->createNativeQuery('SELECT ' . $columns . ' FROM modernorm_test_partial_tracking WHERE id = 1', $mapping)->getSingleResult();
        self::assertInstanceOf(PartialTrackingRecord::class, $result);
        self::assertTrue($this->em->isUninitializedObject($result));
        return $result;
    }

    public static function nullableChanges(): iterable
    {
        yield 'loaded null to value' => [null, 'local'];
        yield 'loaded value to null' => ['stored', null];
    }

    #[DataProvider('nullableChanges')]
    public function testLazyInitializationPreservesLocalLoadedFieldsAndOriginalSnapshot(?string $stored, ?string $local): void
    {
        $record = $this->partial($stored);
        $record->title = $local;
        self::assertTrue($this->em->isUninitializedObject($record));
        self::assertSame('stored body', $record->body);
        self::assertSame($local, $record->title);
        $snapshot = $this->em->getUnitOfWork()->getOriginalEntityData($record);
        self::assertArrayHasKey('title', $snapshot);
        self::assertSame($stored, $snapshot['title']);
        $this->statistics->reset();
        $this->em->flush();
        self::assertSame(1, $this->statistics->snapshot()->dml->update->count);
        self::assertSame($local, $this->em->getConnection()->fetchOne('SELECT title FROM modernorm_test_partial_tracking WHERE id = 1'));
        $this->statistics->reset();
        $this->em->flush();
        self::assertSame(0, $this->statistics->snapshot()->dml->update->count);
    }

    public function testRefreshDiscardsLocalChangesAndCompletesPartialTracking(): void
    {
        $record = $this->partial('stored');
        $record->title = 'local';
        $this->em->refresh($record);
        self::assertFalse($this->em->isUninitializedObject($record));
        self::assertSame('stored', $record->title);
        self::assertSame('stored body', $record->body);
        $this->statistics->reset();
        $this->em->flush();
        $this->em->flush();
        self::assertSame(0, $this->statistics->snapshot()->dml->update->count);
    }

    public function testLoadedDateTimeAndNoOpInitializationDoNotProduceUpdates(): void
    {
        $record = $this->partial(withDate: true);
        $date = $record->createdAt;
        self::assertTrue($this->em->isUninitializedObject($record));
        self::assertSame('2026-01-01', $date->format('Y-m-d'));
        self::assertSame('stored body', $record->body);
        self::assertSame($date, $record->createdAt);
        $this->statistics->reset();
        $this->em->flush();
        $this->em->flush();
        self::assertSame(0, $this->statistics->snapshot()->dml->update->count);
    }

    public static function releaseActions(): iterable
    {
        yield 'detach' => ['detach'];
        yield 'clear' => ['clear'];
    }

    #[DataProvider('releaseActions')]
    public function testRemovingPartialObjectAllowsCollectionAndCleanReload(string $action): void
    {
        $record = $this->partial('stored');
        $reference = WeakReference::create($record);
        if ($action === 'detach') {
            $this->em->detach($record);
        } else {
            $this->em->clear();
        }
        unset($record);
        gc_collect_cycles();
        self::assertNull($reference->get());
        $loaded = $this->em->find(PartialTrackingRecord::class, 1);
        self::assertInstanceOf(PartialTrackingRecord::class, $loaded);
        self::assertSame('stored', $loaded->title);
        $this->statistics->reset();
        $this->em->flush();
        self::assertSame(0, $this->statistics->snapshot()->dml->update->count);
    }

    public function testCompletedProxyReconciliationIsNotRepeatedOnEveryFlush(): void
    {
        $detail = new PartialTrackingDetail();
        $owner = new PartialTrackingOwner();
        $owner->detail = $detail;
        $detail->owner = $owner;
        $this->em->persist($detail);
        $this->em->persist($owner);
        $this->em->flush();
        $this->em->clear();
        $reference = $this->em->getReference(PartialTrackingDetail::class, 1);
        self::assertTrue($this->em->isUninitializedObject($reference));
        $loaded = $this->em->find(PartialTrackingOwner::class, 1);
        self::assertSame($reference, $loaded->detail);
        // Hydration sets the last outstanding inverse property without createEntity() for the detail.
        self::assertFalse($this->em->isUninitializedObject($reference));
        $this->em->flush();
        $this->statistics->reset();
        $this->em->flush();
        self::assertSame(0, $this->statistics->snapshot()->dml->update->count);
    }
}
