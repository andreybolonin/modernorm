<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Events;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

#[ORM\Embeddable]
final class ReadOnlyDetails
{
    #[ORM\Column] public string $code = 'original-code';
}

#[ORM\Entity, ORM\Immutable, ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'modernorm_test_ro_catalogs')]
final class ReadOnlyCatalog
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue] public ?int $id = null;
    #[ORM\Column] public string $name = 'original';
    #[ORM\Version, ORM\Column] public int $version = 1;
    #[ORM\Embedded(class: ReadOnlyDetails::class)] public ReadOnlyDetails $details;
    #[ORM\ManyToOne(targetEntity: ReadOnlyRegion::class, cascade: ['persist'])] public ?ReadOnlyRegion $region = null;
    #[ORM\OneToMany(mappedBy: 'catalog', targetEntity: ReadOnlyChild::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    public Collection $children;
    #[ORM\ManyToMany(targetEntity: ReadOnlyTag::class, cascade: ['persist'])]
    #[ORM\JoinTable(name: 'modernorm_test_ro_catalog_tags')]
    public Collection $tags;
    public array $events = [];

    public function __construct()
    {
        $this->details = new ReadOnlyDetails();
        $this->children = new ArrayCollection();
        $this->tags = new ArrayCollection();
    }
    #[ORM\PrePersist] public function prePersist(): void
    {
        $this->events[] = 'prePersist';
    }
    #[ORM\PostPersist] public function postPersist(): void
    {
        $this->events[] = 'postPersist';
    }
    #[ORM\PreUpdate] public function preUpdate(): void
    {
        $this->events[] = 'preUpdate';
    }
    #[ORM\PostUpdate] public function postUpdate(): void
    {
        $this->events[] = 'postUpdate';
    }
    #[ORM\PreRemove] public function preRemove(): void
    {
        $this->events[] = 'preRemove';
    }
    #[ORM\PostRemove] public function postRemove(): void
    {
        $this->events[] = 'postRemove';
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_ro_regions')]
final class ReadOnlyRegion
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue] public ?int $id = null;
    public function __construct(#[ORM\Column] public string $name = 'region')
    {
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_ro_tags')]
final class ReadOnlyTag
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue] public ?int $id = null;
    public function __construct(#[ORM\Column] public string $name)
    {
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_ro_children')]
final class ReadOnlyChild
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue] public ?int $id = null;
    public function __construct(
        #[ORM\ManyToOne(targetEntity: ReadOnlyCatalog::class, inversedBy: 'children')]
        #[ORM\JoinColumn(nullable: false)] public ReadOnlyCatalog $catalog,
        #[ORM\Column] public string $name,
    ) {
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_ro_owners')]
final class ReadOnlyOwner
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue] public ?int $id = null;
    public function __construct(
        #[ORM\ManyToOne(targetEntity: ReadOnlyCatalog::class, cascade: ['persist'], batchSize: 2)] public ReadOnlyCatalog $catalog,
    ) {
    }
}

#[ORM\Entity(readOnly: true), ORM\Table(name: 'modernorm_test_ro_legacy')]
final class LegacyReadOnlyRecord
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue] public ?int $id = null;
    #[ORM\Column] public string $name = 'legacy';
}

final class ReadOnlySqlRecorder extends AbstractLogger
{
    public array $queries = [];
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql'])) {
            $this->queries[] = $context['sql'];
        }
    }
}

final class ImmutableEntityTest extends TestCase
{
    private EntityManager $em;
    private ReadOnlySqlRecorder $recorder;
    private Statistics $statistics;

    protected function setUp(): void
    {
        $this->recorder = new ReadOnlySqlRecorder();
        $this->statistics = new Statistics(enabled: true);
        $dbal = new DBALConfiguration();
        $dbal->setMiddlewares([new LoggingMiddleware($this->recorder), new Middleware($this->statistics)]);
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $dbal), $config);
        $metadata = array_map($this->em->getClassMetadata(...), [ReadOnlyCatalog::class, ReadOnlyRegion::class, ReadOnlyChild::class, ReadOnlyTag::class, ReadOnlyOwner::class, LegacyReadOnlyRecord::class]);
        $schema = new SchemaTool($this->em);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DROP TABLE IF EXISTS modernorm_test_ro_catalog_tags, modernorm_test_ro_children, modernorm_test_ro_owners, modernorm_test_ro_catalogs, modernorm_test_ro_tags, modernorm_test_ro_regions, modernorm_test_ro_legacy CASCADE');
        $this->em->getConnection()->close();
    }

    private function storedCatalog(): ReadOnlyCatalog
    {
        $catalog = new ReadOnlyCatalog();
        $this->em->persist($catalog);
        $this->em->flush();
        return $catalog;
    }

    private function assertNoCatalogUpdates(): void
    {
        $updates = array_filter($this->recorder->queries, static fn (string $sql): bool => preg_match('/^UPDATE\s+"?modernorm_test_ro_catalogs\b/i', $sql) === 1);
        self::assertSame([], array_values($updates));
    }

    public function testSchemaInsertAndDeleteWorkWithLifecycleCallbacks(): void
    {
        $catalog = $this->storedCatalog();
        self::assertNotNull($catalog->id);
        self::assertSame(1, $catalog->version);
        self::assertSame(['prePersist', 'postPersist'], $catalog->events);
        self::assertTrue($this->em->getUnitOfWork()->isReadOnly($catalog));
        $id = $catalog->id;
        $this->em->remove($catalog);
        $this->em->flush();
        self::assertSame(['prePersist', 'postPersist', 'preRemove', 'postRemove'], $catalog->events);
        self::assertFalse($this->em->getConnection()->fetchOne('SELECT id FROM modernorm_test_ro_catalogs WHERE id = ?', [$id]));
        $this->assertNoCatalogUpdates();
    }

    public function testScalarEmbeddedAndForeignKeyChangesAreIgnoredButCascadePersistWorks(): void
    {
        $catalog = new ReadOnlyCatalog();
        $catalog->region = new ReadOnlyRegion('original-region');
        $this->em->persist($catalog);
        $this->em->flush();
        $originalRegion = $catalog->region->id;
        $catalog->name = 'changed';
        $catalog->details->code = 'changed-code';
        $catalog->region = new ReadOnlyRegion('new-region');
        $this->em->flush();
        self::assertNotNull($catalog->region->id);
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_ro_regions'));
        $data = $this->em->getConnection()->fetchAssociative('SELECT name, details_code, region_id, version FROM modernorm_test_ro_catalogs WHERE id = ?', [$catalog->id]);
        self::assertSame('original', $data['name']);
        self::assertSame('original-code', $data['details_code']);
        self::assertSame($originalRegion, $data['region_id']);
        self::assertSame(1, $data['version']);
        self::assertSame(['prePersist', 'postPersist'], $catalog->events);
        $this->assertNoCatalogUpdates();
    }

    public function testMutableChildrenUpdateAndNewChildrenCascadePersist(): void
    {
        $catalog = new ReadOnlyCatalog();
        $child = new ReadOnlyChild($catalog, 'old');
        $catalog->children->add($child);
        $this->em->persist($catalog);
        $this->em->flush();
        $id = $catalog->id;
        $this->em->clear();
        $catalog = $this->em->find(ReadOnlyCatalog::class, $id);
        $catalog->children->first()->name = 'updated';
        $catalog->children->add(new ReadOnlyChild($catalog, 'new'));
        $catalog->name = 'ignored';
        $this->em->flush();
        self::assertSame(['updated', 'new'], $this->em->getConnection()->fetchFirstColumn('SELECT name FROM modernorm_test_ro_children ORDER BY id'));
        $this->assertNoCatalogUpdates();
    }

    public function testOrphanRemovalAndCascadeDeleteRemainAvailable(): void
    {
        $catalog = new ReadOnlyCatalog();
        $catalog->children->add(new ReadOnlyChild($catalog, 'orphan'));
        $catalog->children->add(new ReadOnlyChild($catalog, 'cascade'));
        $this->em->persist($catalog);
        $this->em->flush();
        $orphan = $catalog->children->first();
        $catalog->children->removeElement($orphan);
        $this->em->flush();
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_ro_children'));
        $this->em->remove($catalog);
        $this->em->flush();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_ro_children'));
        $this->assertNoCatalogUpdates();
    }

    public function testManyToManyLinksCanBeAddedRemovedAndCollectionReplaced(): void
    {
        $catalog = $this->storedCatalog();
        $catalog->tags->add(new ReadOnlyTag('first'));
        $this->em->flush();
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_ro_catalog_tags'));
        $catalog->tags->removeElement($catalog->tags->first());
        $this->em->flush();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_ro_catalog_tags'));
        $catalog->tags->add(new ReadOnlyTag('second'));
        $this->em->flush();
        $catalog->tags = new ArrayCollection([new ReadOnlyTag('replacement')]);
        $this->em->flush();
        self::assertSame(['replacement'], $this->em->getConnection()->fetchFirstColumn('SELECT t.name FROM modernorm_test_ro_tags t JOIN modernorm_test_ro_catalog_tags j ON j.readonlytag_id = t.id'));
        self::assertSame(1, $catalog->version);
        $this->assertNoCatalogUpdates();
    }

    public function testLazyBatchHydrationIdentityAndRefreshPreserveReadOnlyPolicy(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->em->persist(new ReadOnlyOwner(new ReadOnlyCatalog()));
        }
        $this->em->flush();
        $this->em->clear();
        $owners = $this->em->getRepository(ReadOnlyOwner::class)->findBy([], ['id' => 'ASC']);
        self::assertTrue($this->em->isUninitializedObject($owners[0]->catalog));
        $this->statistics->reset();
        self::assertSame('original', $owners[0]->catalog->name);
        self::assertFalse($this->em->isUninitializedObject($owners[1]->catalog));
        self::assertTrue($this->em->isUninitializedObject($owners[2]->catalog));
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        $catalog = $owners[0]->catalog;
        self::assertSame($catalog, $this->em->find(ReadOnlyCatalog::class, $catalog->id));
        $catalog->name = 'ignored';
        $this->em->flush();
        $this->em->refresh($catalog);
        self::assertSame('original', $catalog->name);
        self::assertTrue($this->em->getUnitOfWork()->isReadOnly($catalog));
        $this->assertNoCatalogUpdates();
    }

    public function testInternalChangesetRecomputationCannotScheduleAnEntityUpdate(): void
    {
        $catalog = $this->storedCatalog();
        $catalog->name = 'ignored';
        $uow = $this->em->getUnitOfWork();
        $metadata = $this->em->getClassMetadata(ReadOnlyCatalog::class);
        $uow->computeChangeSet($metadata, $catalog);
        $uow->recomputeSingleEntityChangeSet($metadata, $catalog);
        $uow->propertyChanged($catalog, 'name', 'original', 'ignored');
        $uow->scheduleForUpdate($catalog);
        $this->em->flush();
        self::assertSame('original', $this->em->getConnection()->fetchOne('SELECT name FROM modernorm_test_ro_catalogs WHERE id = ?', [$catalog->id]));
        self::assertSame(['prePersist', 'postPersist'], $catalog->events);
        $this->assertNoCatalogUpdates();
    }

    public function testOnFlushCanStillCompleteAnInsert(): void
    {
        $catalog = new ReadOnlyCatalog();
        $this->em->getEventManager()->addEventListener([Events::onFlush], new class ($catalog, $this->em) {
            public function __construct(private ReadOnlyCatalog $catalog, private EntityManager $em)
            {
            }
            public function onFlush(): void
            {
                $this->catalog->name = 'inserted-on-flush';
                $this->em->getUnitOfWork()->recomputeSingleEntityChangeSet($this->em->getClassMetadata(ReadOnlyCatalog::class), $this->catalog);
            }
        });
        $this->em->persist($catalog);
        $this->em->flush();
        self::assertSame('inserted-on-flush', $this->em->getConnection()->fetchOne('SELECT name FROM modernorm_test_ro_catalogs WHERE id = ?', [$catalog->id]));
        $this->assertNoCatalogUpdates();
    }

    public function testLegacyReadOnlyEntityOptionUsesTheSameFieldPolicy(): void
    {
        $record = new LegacyReadOnlyRecord();
        $this->em->persist($record);
        $this->em->flush();
        $record->name = 'ignored';
        $this->em->flush();
        self::assertSame('legacy', $this->em->getConnection()->fetchOne('SELECT name FROM modernorm_test_ro_legacy WHERE id = ?', [$record->id]));
        $this->em->remove($record);
        $this->em->flush();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_ro_legacy'));
    }
}
