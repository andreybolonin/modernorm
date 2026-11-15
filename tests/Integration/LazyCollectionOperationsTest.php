<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\PersistentCollection;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_lazy_folders')]
final class LazyFolder
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    /** @var Collection<string, LazyEntry> */
    #[ORM\OneToMany(mappedBy: 'folder', targetEntity: LazyEntry::class, cascade: ['persist'], orphanRemoval: true, fetch: 'LAZY', indexBy: 'slug')]
    #[ORM\OrderBy(['slug' => 'ASC'])]
    public Collection $entries;

    public function __construct()
    {
        $this->entries = new ArrayCollection();
    }

    public function add(string $slug): LazyEntry
    {
        $entry = new LazyEntry($slug, $this);
        $this->entries->add($entry);

        return $entry;
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_lazy_entries')]
final class LazyEntry
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column] public string $slug,
        #[ORM\ManyToOne(inversedBy: 'entries')]
        #[ORM\JoinColumn(nullable: false)] public LazyFolder $folder,
    ) {
    }
}

final class LazyCollectionQueryRecorder extends AbstractLogger
{
    /** @var list<string> */
    public array $sql = [];

    public function firstSql(): string
    {
        return $this->sql[0] ?? '';
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql'])) {
            $this->sql[] = $context['sql'];
        }
    }
}

final class LazyCollectionOperationsTest extends TestCase
{
    private EntityManager $em;
    private LazyCollectionQueryRecorder $queries;

    private function assertInitialization(Collection $collection, bool $initialized): void
    {
        self::assertInstanceOf(PersistentCollection::class, $collection);
        self::assertSame($initialized, $collection->isInitialized());
    }

    protected function setUp(): void
    {
        $this->queries = new LazyCollectionQueryRecorder();
        $config = new DBALConfiguration();
        $config->setMiddlewares([new Middleware($this->queries)]);
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $config);
        $this->em = new EntityManager($connection, ORMSetup::createAttributeMetadataConfig(
            [__DIR__],
            true,
            cache: new ArrayAdapter(),
        ));
        $metadata = array_map($this->em->getClassMetadata(...), [LazyFolder::class, LazyEntry::class]);
        $schema = new SchemaTool($this->em);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DROP TABLE IF EXISTS modernorm_test_lazy_entries, modernorm_test_lazy_folders CASCADE');
        $this->em->getConnection()->close();
    }

    /** @return array{LazyFolder, int, int} */
    private function seed(): array
    {
        $folder = new LazyFolder();
        // Insert out of order so slice/first must use the mapped ordering.
        $folder->add('charlie');
        $alpha = $folder->add('alpha');
        $folder->add('bravo');
        $other = new LazyFolder();
        $foreign = $other->add('foreign');
        $empty = new LazyFolder();
        foreach ([$folder, $other, $empty] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $id = $folder->id;
        $alphaId = $alpha->id;
        $foreignId = $foreign->id;
        $this->em->clear();
        $folder = $this->em->find(LazyFolder::class, $id);
        $this->queries->sql = [];

        return [$folder, $alphaId, $foreignId];
    }

    public function testContainsInitializesCollectionAndChecksMembership(): void
    {
        [$folder, $alphaId, $foreignId] = $this->seed();
        $alpha = $this->em->getReference(LazyEntry::class, $alphaId);
        $foreign = $this->em->getReference(LazyEntry::class, $foreignId);
        self::assertTrue($folder->entries->contains($alpha));
        self::assertFalse($folder->entries->contains($foreign));
        self::assertCount(1, $this->queries->sql);
        self::assertFalse($folder->entries->contains(new LazyEntry('new', $folder)));
        self::assertCount(1, $this->queries->sql);
        $this->assertInitialization($folder->entries, true);
        self::assertFalse($this->em->isUninitializedObject($alpha));
    }

    public function testIndexedLookupUsesOwnerAndPreservesIdentityMap(): void
    {
        [$folder, $alphaId] = $this->seed();
        self::assertTrue($folder->entries->containsKey('alpha'));
        self::assertFalse($folder->entries->containsKey('foreign'));
        self::assertFalse($folder->entries->containsKey('missing'));
        self::assertCount(1, $this->queries->sql);
        $entry = $folder->entries->get('alpha');
        self::assertSame($alphaId, $entry->id);
        self::assertSame($folder, $entry->folder);
        self::assertSame($entry, $this->em->find(LazyEntry::class, $alphaId));
        self::assertCount(1, $this->queries->sql);
        self::assertNull($folder->entries->get('foreign'));
        self::assertNull($folder->entries->get('missing'));
        self::assertCount(1, $this->queries->sql);
        $this->assertInitialization($folder->entries, true);
    }

    public function testSliceAndFirstInitializeOnceAndRespectOrder(): void
    {
        [$folder, $alphaId] = $this->seed();
        $slice = $folder->entries->slice(1, 1);
        self::assertSame(['bravo'], array_keys($slice));
        self::assertSame('bravo', $slice['bravo']->slug);
        self::assertCount(1, $this->queries->sql);
        $first = $folder->entries->first();
        self::assertSame($alphaId, $first->id);
        self::assertSame($first, $this->em->find(LazyEntry::class, $alphaId));
        self::assertCount(1, $this->queries->sql);
        self::assertSame([], $folder->entries->slice(10, 2));
        self::assertCount(1, $this->queries->sql);
        $this->assertInitialization($folder->entries, true);
        self::assertSame(['bravo', 'charlie'], array_keys($folder->entries->slice(1)));
        self::assertCount(1, $this->queries->sql);
    }

    public function testEmptyCollectionInitializesOnce(): void
    {
        [$folder] = $this->seed();
        self::assertFalse($folder->entries->isEmpty());
        self::assertCount(1, $this->queries->sql);
        $emptyId = $this->em->getConnection()->fetchOne('SELECT MAX(id) FROM modernorm_test_lazy_folders');
        $empty = $this->em->find(LazyFolder::class, $emptyId);
        $this->queries->sql = [];
        self::assertTrue($empty->entries->isEmpty());
        self::assertFalse($empty->entries->first());
        self::assertSame([], $empty->entries->slice(0, 2));
        self::assertCount(1, $this->queries->sql);
        $this->assertInitialization($empty->entries, true);
    }

    public function testPendingAdditionSurvivesCollectionInitialization(): void
    {
        [$folder] = $this->seed();
        $new = $folder->add('delta');
        self::assertCount(0, $this->queries->sql);
        $this->assertInitialization($folder->entries, false);
        self::assertTrue($folder->entries->contains($new));
        self::assertFalse($folder->entries->isEmpty());
        self::assertCount(1, $this->queries->sql);
        self::assertSame(4, $folder->entries->count());
        self::assertCount(1, $this->queries->sql);
        $slice = $folder->entries->slice(0, 10);
        self::assertCount(4, $slice);
        self::assertContains($new, $slice);
        $this->assertInitialization($folder->entries, true);
        self::assertCount(1, $this->queries->sql);
        $this->em->flush();
        $id = $folder->id;
        $this->em->clear();
        $entries = $this->em->find(LazyFolder::class, $id)->entries;
        self::assertSame(4, $entries->count());
        self::assertSame($new->id, $entries->get('delta')->id);
    }

    public function testRemoveElementLoadsCollectionAndDefersOrphanDeleteUntilFlush(): void
    {
        [$folder, $alphaId] = $this->seed();
        $alpha = $this->em->getReference(LazyEntry::class, $alphaId);
        self::assertTrue($folder->entries->removeElement($alpha));
        $this->assertInitialization($folder->entries, true);
        self::assertCount(1, $this->queries->sql);
        self::assertStringStartsWith('SELECT', $this->queries->firstSql());
        self::assertSame(2, $folder->entries->count());
        self::assertFalse($folder->entries->removeElement($alpha));
        self::assertCount(1, $this->queries->sql);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_lazy_entries WHERE id = ?', [$alphaId]));
        $this->em->flush();
        $id = $folder->id;
        $this->em->clear();
        self::assertNull($this->em->find(LazyEntry::class, $alphaId));
        self::assertSame(2, $this->em->find(LazyFolder::class, $id)->entries->count());
    }
}
