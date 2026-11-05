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
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_lazy_playlists')]
final class LazyPlaylist
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    /** @var Collection<string, LazyTrack> */
    #[ORM\ManyToMany(targetEntity: LazyTrack::class, inversedBy: 'playlists', cascade: ['persist'], fetch: 'EXTRA_LAZY', indexBy: 'slug')]
    #[ORM\JoinTable(name: 'modernorm_test_lazy_playlist_tracks')]
    #[ORM\OrderBy(['slug' => 'ASC'])]
    public Collection $tracks;

    public function __construct(#[ORM\Column] public string $slug)
    {
        $this->tracks = new ArrayCollection();
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_lazy_tracks')]
final class LazyTrack
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    /** @var Collection<string, LazyPlaylist> */
    #[ORM\ManyToMany(targetEntity: LazyPlaylist::class, mappedBy: 'tracks', fetch: 'EXTRA_LAZY', indexBy: 'slug')]
    #[ORM\OrderBy(['slug' => 'ASC'])]
    public Collection $playlists;

    public function __construct(#[ORM\Column] public string $slug)
    {
        $this->playlists = new ArrayCollection();
    }
}

final class ManyToManyQueryRecorder extends AbstractLogger
{
    /** @var list<string> */
    public array $sql = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql'])) {
            $this->sql[] = $context['sql'];
        }
    }
}

final class ExtraLazyManyToManyTest extends TestCase
{
    private EntityManager $em;
    private ManyToManyQueryRecorder $queries;

    protected function setUp(): void
    {
        $this->queries = new ManyToManyQueryRecorder();
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
        $metadata = array_map($this->em->getClassMetadata(...), [LazyPlaylist::class, LazyTrack::class]);
        $schema = new SchemaTool($this->em);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DROP TABLE IF EXISTS modernorm_test_lazy_playlist_tracks, modernorm_test_lazy_tracks, modernorm_test_lazy_playlists CASCADE');
        $this->em->getConnection()->close();
    }

    /** @return array{array<string, int>, array<string, int>} */
    private function seed(): array
    {
        $playlists = $tracks = [];
        foreach (['charlie', 'alpha', 'bravo', 'foreign', 'empty'] as $slug) {
            $playlists[$slug] = new LazyPlaylist($slug);
            $tracks[$slug] = new LazyTrack($slug);
            $this->em->persist($playlists[$slug]);
            $this->em->persist($tracks[$slug]);
        }
        foreach (['charlie', 'alpha', 'bravo'] as $slug) {
            $playlists['alpha']->tracks->add($tracks[$slug]);
            if ($slug !== 'alpha') {
                $playlists[$slug]->tracks->add($tracks['alpha']);
            }
        }
        $playlists['foreign']->tracks->add($tracks['foreign']);
        $this->em->flush();
        $playlistIds = array_map(static fn (LazyPlaylist $p): int => $p->id, $playlists);
        $trackIds = array_map(static fn (LazyTrack $t): int => $t->id, $tracks);
        $this->em->clear();
        $this->queries->sql = [];

        return [$playlistIds, $trackIds];
    }

    public static function sides(): array
    {
        return ['owning' => [true], 'inverse' => [false]];
    }

    /** @return array{Collection, string, array<string, int>, Collection} */
    private function collections(bool $owning): array
    {
        [$playlistIds, $trackIds] = $this->seed();
        $sourceClass = $owning ? LazyPlaylist::class : LazyTrack::class;
        $targetClass = $owning ? LazyTrack::class : LazyPlaylist::class;
        $sourceIds = $owning ? $playlistIds : $trackIds;
        $targetIds = $owning ? $trackIds : $playlistIds;
        $field = $owning ? 'tracks' : 'playlists';
        $collection = $this->em->find($sourceClass, $sourceIds['alpha'])->$field;
        $empty = $this->em->find($sourceClass, $sourceIds['empty'])->$field;
        $this->queries->sql = [];

        return [$collection, $targetClass, $targetIds, $empty];
    }

    #[DataProvider('sides')]
    public function testCountContainsAndEmptyWithoutCollectionInitialization(bool $owning): void
    {
        [$collection, $class, $ids, $empty] = $this->collections($owning);
        $member = $this->em->getReference($class, $ids['alpha']);
        $foreign = $this->em->getReference($class, $ids['foreign']);
        self::assertSame(3, $collection->count());
        self::assertTrue($collection->contains($member));
        self::assertFalse($collection->contains($foreign));
        self::assertFalse($collection->isEmpty());
        self::assertCount(4, $this->queries->sql);
        self::assertFalse($collection->contains(new $class('new')));
        self::assertCount(4, $this->queries->sql);
        self::assertFalse($collection->isInitialized());
        self::assertTrue($this->em->isUninitializedObject($member));
        self::assertTrue($empty->isEmpty());
        self::assertFalse($empty->first());
        self::assertSame([], $empty->slice(0, 2));
        self::assertCount(7, $this->queries->sql);
        self::assertFalse($empty->isInitialized());
    }

    #[DataProvider('sides')]
    public function testIndexedLookupAndOrderedSlicePreserveIdentity(bool $owning): void
    {
        [$collection, $class, $ids] = $this->collections($owning);
        self::assertTrue($collection->containsKey('alpha'));
        self::assertFalse($collection->containsKey('foreign'));
        self::assertFalse($collection->containsKey('missing'));
        self::assertCount(3, $this->queries->sql);
        $member = $collection->get('alpha');
        self::assertSame($ids['alpha'], $member->id);
        self::assertSame($member, $this->em->find($class, $ids['alpha']));
        self::assertCount(4, $this->queries->sql);
        self::assertNull($collection->get('foreign'));
        self::assertNull($collection->get('missing'));
        self::assertCount(6, $this->queries->sql);
        self::assertSame($member, $collection->first());
        self::assertCount(7, $this->queries->sql);
        $slice = $collection->slice(1, 1);
        self::assertSame(['bravo'], array_keys($slice));
        self::assertSame($ids['bravo'], $slice['bravo']->id);
        self::assertSame($slice['bravo'], $this->em->find($class, $ids['bravo']));
        self::assertCount(8, $this->queries->sql);
        self::assertSame([], $collection->slice(20, 1));
        self::assertCount(9, $this->queries->sql);
        self::assertFalse($collection->isInitialized());
    }

    public function testOwningAdditionPersistsNewTargetAndJoinWithoutLoadingCollection(): void
    {
        [$playlistIds] = $this->seed();
        $playlist = $this->em->find(LazyPlaylist::class, $playlistIds['alpha']);
        $this->queries->sql = [];
        $new = new LazyTrack('delta');
        $playlist->tracks->add($new);
        self::assertTrue($playlist->tracks->contains($new));
        self::assertCount(0, $this->queries->sql);
        self::assertSame(4, $playlist->tracks->count());
        self::assertCount(1, $this->queries->sql);
        self::assertFalse($playlist->tracks->isInitialized());
        $this->queries->sql = [];
        $this->em->flush();
        self::assertNotNull($new->id);
        self::assertFalse($playlist->tracks->isInitialized());
        self::assertCount(0, array_filter($this->queries->sql, static fn (string $sql): bool => str_starts_with($sql, 'SELECT')));
        $this->em->clear();
        $playlist = $this->em->find(LazyPlaylist::class, $playlistIds['alpha']);
        self::assertSame(4, $playlist->tracks->count());
        self::assertSame($new->id, $playlist->tracks->get('delta')->id);
        $track = $this->em->find(LazyTrack::class, $new->id);
        self::assertTrue($track->playlists->contains($playlist));
    }

    public function testDirtySliceIncludesPendingExistingTargetAndInitializesCollection(): void
    {
        [$playlistIds, $trackIds] = $this->seed();
        $playlist = $this->em->find(LazyPlaylist::class, $playlistIds['alpha']);
        $foreign = $this->em->getReference(LazyTrack::class, $trackIds['foreign']);
        $this->queries->sql = [];
        $playlist->tracks->add($foreign);
        self::assertCount(0, $this->queries->sql);
        self::assertCount(4, $playlist->tracks->slice(0, 10));
        self::assertTrue($playlist->tracks->isInitialized());
        self::assertTrue($playlist->tracks->contains($foreign));
        self::assertCount(1, $this->queries->sql);
        $this->em->flush();
        $this->em->clear();
        self::assertSame(4, $this->em->find(LazyPlaylist::class, $playlistIds['alpha'])->tracks->count());
        self::assertSame(2, $this->em->find(LazyTrack::class, $trackIds['foreign'])->playlists->count());
    }

    public function testOwningRemovalDefersJoinDeleteAndPreservesSharedTarget(): void
    {
        [$playlistIds, $trackIds] = $this->seed();
        $playlist = $this->em->find(LazyPlaylist::class, $playlistIds['alpha']);
        $track = $this->em->getReference(LazyTrack::class, $trackIds['alpha']);
        $this->queries->sql = [];
        self::assertTrue($playlist->tracks->removeElement($track));
        self::assertTrue($playlist->tracks->isInitialized());
        self::assertCount(1, $this->queries->sql);
        self::assertStringStartsWith('SELECT', $this->queries->sql[0]);
        $connection = $this->em->getConnection();
        self::assertSame(6, (int) $connection->fetchOne('SELECT COUNT(*) FROM modernorm_test_lazy_playlist_tracks'));
        $this->em->flush();
        $this->em->clear();
        self::assertSame(5, (int) $connection->fetchOne('SELECT COUNT(*) FROM modernorm_test_lazy_playlist_tracks'));
        self::assertSame(2, $this->em->find(LazyPlaylist::class, $playlistIds['alpha'])->tracks->count());
        $track = $this->em->find(LazyTrack::class, $trackIds['alpha']);
        self::assertNotNull($track);
        self::assertSame(2, $track->playlists->count());
    }

    public function testInverseOnlyChangesAreNotWrittenToJoinTable(): void
    {
        [$playlistIds, $trackIds] = $this->seed();
        $track = $this->em->find(LazyTrack::class, $trackIds['alpha']);
        $foreign = $this->em->getReference(LazyPlaylist::class, $playlistIds['foreign']);
        $this->queries->sql = [];
        $track->playlists->add($foreign);
        self::assertCount(0, $this->queries->sql);
        self::assertSame(4, $track->playlists->count());
        self::assertFalse($track->playlists->isInitialized());
        $this->em->flush();
        $this->em->clear();
        $track = $this->em->find(LazyTrack::class, $trackIds['alpha']);
        self::assertSame(3, $track->playlists->count());
        self::assertFalse($track->playlists->contains($this->em->getReference(LazyPlaylist::class, $playlistIds['foreign'])));
        $this->queries->sql = [];
        self::assertTrue($track->playlists->removeElement($this->em->getReference(LazyPlaylist::class, $playlistIds['alpha'])));
        self::assertTrue($track->playlists->isInitialized());
        self::assertCount(1, $this->queries->sql);
        $this->em->flush();
        $this->em->clear();
        self::assertSame(3, $this->em->find(LazyTrack::class, $trackIds['alpha'])->playlists->count());
        self::assertSame(3, $this->em->find(LazyPlaylist::class, $playlistIds['alpha'])->tracks->count());
    }
}
