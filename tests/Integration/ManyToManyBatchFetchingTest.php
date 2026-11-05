<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use ModernORM\Configuration;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\Mapping\Driver\AttributeDriver;
use ModernORM\Statistics;
use ModernORM\Statistics\Middleware;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_m2m_batch_groups')]
final class ManyBatchGroup
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column(unique: true)] public string $name;
    #[ORM\ManyToMany(targetEntity: ManyBatchRole::class, inversedBy: 'groups', cascade: ['persist'], indexBy: 'name', batchSize: 3)]
    #[ORM\JoinTable(name: 'modernorm_test_m2m_batch_links', joinColumns: [new ORM\JoinColumn(name: 'group_id')], inverseJoinColumns: [new ORM\JoinColumn(name: 'role_id', onDelete: 'CASCADE')])]
    #[ORM\OrderBy(['position' => 'ASC'])] public Collection $roles;
    #[ORM\ManyToMany(targetEntity: ManyBatchRole::class, inversedBy: 'favoredBy', batchSize: 2)]
    #[ORM\JoinTable(name: 'modernorm_test_m2m_batch_favorites', joinColumns: [new ORM\JoinColumn(name: 'group_id')], inverseJoinColumns: [new ORM\JoinColumn(name: 'role_id', onDelete: 'CASCADE')])]
    #[ORM\OrderBy(['position' => 'DESC'])] public Collection $favorites;
    #[ORM\ManyToMany(targetEntity: ManyBatchRole::class, inversedBy: 'extraGroups', fetch: 'EXTRA_LAZY', indexBy: 'name', batchSize: 3)]
    #[ORM\JoinTable(name: 'modernorm_test_m2m_batch_extra', joinColumns: [new ORM\JoinColumn(name: 'group_id')], inverseJoinColumns: [new ORM\JoinColumn(name: 'role_id', onDelete: 'CASCADE')])]
    #[ORM\OrderBy(['position' => 'ASC'])] public Collection $extraRoles;
    #[ORM\ManyToMany(targetEntity: ManyBatchRole::class)]
    #[ORM\JoinTable(name: 'modernorm_test_m2m_batch_defaults', joinColumns: [new ORM\JoinColumn(name: 'group_id')], inverseJoinColumns: [new ORM\JoinColumn(name: 'role_id', onDelete: 'CASCADE')])]
    public Collection $defaultRoles;
    #[ORM\ManyToMany(targetEntity: ManyBatchRole::class, batchSize: 1)]
    #[ORM\JoinTable(name: 'modernorm_test_m2m_batch_disabled', joinColumns: [new ORM\JoinColumn(name: 'group_id')], inverseJoinColumns: [new ORM\JoinColumn(name: 'role_id', onDelete: 'CASCADE')])]
    public Collection $disabledRoles;
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_m2m_batch_roles')]
final class ManyBatchRole
{
    public function __construct(
        #[ORM\Id, ORM\Column(length: 32)] public string $id,
        #[ORM\Column(unique: true)] public string $name,
        #[ORM\Column] public int $position,
    ) {}
    #[ORM\ManyToMany(targetEntity: ManyBatchGroup::class, mappedBy: 'roles', indexBy: 'name', batchSize: 3)]
    #[ORM\OrderBy(['name' => 'ASC'])] public Collection $groups;
    #[ORM\ManyToMany(targetEntity: ManyBatchGroup::class, mappedBy: 'favorites', batchSize: 2)] public Collection $favoredBy;
    #[ORM\ManyToMany(targetEntity: ManyBatchGroup::class, mappedBy: 'extraRoles', fetch: 'EXTRA_LAZY', indexBy: 'name', batchSize: 3)]
    #[ORM\OrderBy(['name' => 'ASC'])] public Collection $extraGroups;
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_m2m_batch_nodes')]
final class ManyBatchNode
{
    #[ORM\Id, ORM\Column] public int $id;
    #[ORM\Column] public string $name;
    #[ORM\ManyToMany(targetEntity: self::class, inversedBy: 'followedBy', indexBy: 'id', batchSize: 2)]
    #[ORM\JoinTable(name: 'modernorm_test_m2m_batch_follows', joinColumns: [new ORM\JoinColumn(name: 'source_id', onDelete: 'CASCADE')], inverseJoinColumns: [new ORM\JoinColumn(name: 'target_id', onDelete: 'CASCADE')])]
    #[ORM\OrderBy(['name' => 'DESC'])] public Collection $follows;
    #[ORM\ManyToMany(targetEntity: self::class, mappedBy: 'follows', indexBy: 'name', batchSize: 2)]
    #[ORM\OrderBy(['name' => 'ASC'])] public Collection $followedBy;
}

final class ManyBatchRecorder extends AbstractLogger
{
    public array $sql = [];
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['sql'])) { $this->sql[] = $context['sql']; }
    }
}

final class ManyToManyBatchFetchingTest extends TestCase
{
    private EntityManager $em;
    private Statistics $statistics;
    private ManyBatchRecorder $recorder;
    private const IDS = ['01', '1', 'a b', 'four', 'five', 'six', 'seven'];
    private const NAMES = ['b', 'a', 'shared', 'd', 'e', 'f', 'g'];

    protected function setUp(): void
    {
        $this->statistics = new Statistics(enabled: true);
        $this->recorder = new ManyBatchRecorder();
        $dbal = new DBALConfiguration();
        $dbal->setMiddlewares([new Middleware($this->statistics), new LoggingMiddleware($this->recorder)]);
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([]));
        $this->em = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $dbal), $config);
        $metadata = array_map($this->em->getClassMetadata(...), [ManyBatchGroup::class, ManyBatchRole::class, ManyBatchNode::class]);
        $schema = new SchemaTool($this->em);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        foreach (self::IDS as $index => $id) {
            $this->em->getConnection()->insert('modernorm_test_m2m_batch_groups', ['id' => $index + 1, 'name' => 'group-' . ($index + 1)]);
            $this->em->getConnection()->insert('modernorm_test_m2m_batch_roles', ['id' => $id, 'name' => self::NAMES[$index], 'position' => $id === '01' ? 20 : ($id === '1' ? 10 : 30 + $index)]);
        }
        foreach ([[1, '01'], [1, '1'], [1, 'a b'], [2, 'a b'], [4, 'four'], [6, 'six'], [7, 'seven']] as [$group, $role]) {
            foreach (['links', 'favorites', 'extra', 'defaults', 'disabled'] as $table) {
                $this->em->getConnection()->insert('modernorm_test_m2m_batch_' . $table, ['group_id' => $group, 'role_id' => $role]);
            }
        }
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DROP TABLE IF EXISTS modernorm_test_m2m_batch_links, modernorm_test_m2m_batch_favorites, modernorm_test_m2m_batch_extra, modernorm_test_m2m_batch_defaults, modernorm_test_m2m_batch_disabled, modernorm_test_m2m_batch_groups, modernorm_test_m2m_batch_roles, modernorm_test_m2m_batch_follows, modernorm_test_m2m_batch_nodes CASCADE');
        $this->em->getConnection()->close();
    }

    private function loadGroups(): array
    {
        return $this->em->getRepository(ManyBatchGroup::class)->findBy([], ['id' => 'ASC']);
    }
    private function loadRoles(): array
    {
        $roles = [];
        foreach (self::IDS as $id) { $roles[$id] = $this->em->find(ManyBatchRole::class, $id); }
        return $roles;
    }
    private function reset(): void { $this->statistics->reset(); $this->recorder->sql = []; }

    public function testOwningSevenCollectionsUseThreeSelectsAndKeepSharedTargets(): void
    {
        $groups = $this->loadGroups();
        $this->reset();
        self::assertSame(['a', 'b', 'shared'], $groups[0]->roles->getKeys());
        self::assertSame(['shared'], $groups[1]->roles->getKeys());
        self::assertTrue($groups[2]->roles->isInitialized());
        self::assertTrue($groups[2]->roles->isEmpty());
        self::assertSame($groups[0]->roles->get('shared'), $groups[1]->roles->get('shared'));
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        self::assertStringContainsString(' IN (', $this->recorder->sql[0]);
        foreach ($groups as $group) { $group->roles->toArray(); }
        self::assertSame(3, $this->statistics->snapshot()->sqlCount);
    }

    public static function l2Modes(): array { return [[false], [true]]; }

    #[DataProvider('l2Modes')]
    public function testInverseBatchesKeepAllMembershipsAndDistinctStringIds(bool $l2): void
    {
        if ($l2) { $this->enableL2(); }
        $roles = $this->loadRoles();
        $this->reset();
        self::assertSame(['group-1', 'group-2'], $roles['a b']->groups->getKeys());
        self::assertSame(['group-1'], $roles['01']->groups->getKeys());
        self::assertSame(['group-1'], $roles['1']->groups->getKeys());
        self::assertSame($roles['01']->groups->first(), $roles['1']->groups->first());
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        foreach ($roles as $role) { $role->groups->toArray(); }
        self::assertTrue($roles['five']->groups->isEmpty());
        self::assertSame(3, $this->statistics->snapshot()->sqlCount);
    }

    public function testRoleQueuesBatchSizesAndUnindexedOrderStaySeparate(): void
    {
        $groups = $this->loadGroups();
        $this->reset();
        $groups[5]->roles->toArray();
        foreach ($groups as $group) { self::assertFalse($group->favorites->isInitialized()); }
        self::assertSame(['a b', '01', '1'], array_map(static fn ($role) => $role->id, $groups[0]->favorites->toArray()));
        self::assertSame([0, 1, 2], $groups[0]->favorites->getKeys());
        self::assertTrue($groups[1]->favorites->isInitialized());
        self::assertFalse($groups[2]->favorites->isInitialized());
        self::assertSame(2, $this->statistics->snapshot()->sqlCount);
    }

    public function testLocalChangesAndIdentityMapSurviveBatchHydration(): void
    {
        $groups = $this->loadGroups();
        $role = $this->em->find(ManyBatchRole::class, '1');
        $role->name = 'local';
        $this->reset();
        self::assertSame($role, $groups[0]->roles->get('local'));
        self::assertSame('local', $role->name);
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        $this->em->flush();
        self::assertSame('local', $this->em->getConnection()->fetchOne('SELECT name FROM modernorm_test_m2m_batch_roles WHERE id = ?', ['1']));
    }

    public function testPendingNewAndExistingAdditionsOnTriggerAndPeerAreFlushed(): void
    {
        $groups = $this->loadGroups();
        $new = new ManyBatchRole('new', 'new-role', 1);
        $existing = $this->em->find(ManyBatchRole::class, 'four');
        $groups[0]->roles->add($new);
        $groups[1]->roles->add($existing);
        $this->reset();
        self::assertCount(4, $groups[0]->roles);
        self::assertTrue($groups[1]->roles->contains($existing));
        self::assertNotContains($new, $groups[0]->roles->getSnapshot());
        self::assertNotContains($existing, $groups[1]->roles->getSnapshot());
        self::assertTrue($groups[0]->roles->isDirty());
        self::assertTrue($groups[1]->roles->isDirty());
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        $this->em->flush();
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_m2m_batch_links WHERE group_id = 1 AND role_id = ?', ['new']));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_m2m_batch_links WHERE group_id = 2 AND role_id = ?', ['four']));
    }

    public function testRemovalKeepsOtherMembershipsAndDoesNotDeleteTarget(): void
    {
        $groups = $this->loadGroups();
        $role = $groups[0]->roles->remove('shared');
        $this->reset();
        $groups[3]->roles->toArray();
        self::assertFalse($groups[0]->roles->contains($role));
        self::assertTrue($groups[1]->roles->contains($role));
        $this->em->flush();
        self::assertSame([2], $this->em->getConnection()->fetchFirstColumn('SELECT group_id FROM modernorm_test_m2m_batch_links WHERE role_id = ?', ['a b']));
        self::assertSame('a b', $this->em->getConnection()->fetchOne('SELECT id FROM modernorm_test_m2m_batch_roles WHERE id = ?', ['a b']));
    }

    public function testInverseEditsRequireOwningSideSynchronization(): void
    {
        $groups = $this->loadGroups();
        $roles = $this->loadRoles();
        $shared = $roles['a b'];
        $shared->groups->remove('group-1');
        $shared->groups->add($groups[2]);
        $this->em->flush();
        self::assertSame([1, 2], $this->em->getConnection()->fetchFirstColumn('SELECT group_id FROM modernorm_test_m2m_batch_links WHERE role_id = ? ORDER BY group_id', ['a b']));
        $groups[0]->roles->removeElement($shared);
        $groups[2]->roles->add($shared);
        $this->em->flush();
        self::assertSame([2, 3], $this->em->getConnection()->fetchFirstColumn('SELECT group_id FROM modernorm_test_m2m_batch_links WHERE role_id = ? ORDER BY group_id', ['a b']));
    }

    public static function sides(): array { return [[true], [false]]; }

    #[DataProvider('sides')]
    public function testExtraLazyOperationsStayPartialUntilFullInitialization(bool $owning): void
    {
        if ($owning) {
            $owners = $this->loadGroups(); $collections = array_map(static fn ($group) => $group->extraRoles, $owners); $trigger = $collections[0]; $key = 'a'; $count = 3;
        } else {
            $owners = $this->loadRoles(); $collections = array_values(array_map(static fn ($role) => $role->extraGroups, $owners)); $trigger = $owners['a b']->extraGroups; $key = 'group-1'; $count = 2;
        }
        self::assertCount($count, $trigger);
        self::assertTrue($trigger->containsKey($key));
        self::assertCount(1, $trigger->slice(0, 1));
        foreach ($collections as $collection) { self::assertFalse($collection->isInitialized()); }
        $this->reset();
        self::assertCount($count, $trigger->toArray());
        self::assertTrue($collections[0]->isInitialized());
        self::assertTrue($collections[1]->isInitialized());
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
    }

    public function testGlobalFallbackAndExplicitOptOut(): void
    {
        $this->em->getConfiguration()->setLazyFetchBatchSize(2);
        $groups = $this->loadGroups();
        $this->reset();
        $groups[0]->defaultRoles->toArray();
        self::assertTrue($groups[1]->defaultRoles->isInitialized());
        self::assertFalse($groups[2]->defaultRoles->isInitialized());
        $groups[0]->disabledRoles->toArray();
        self::assertFalse($groups[1]->disabledRoles->isInitialized());
        self::assertSame(2, $this->statistics->snapshot()->sqlCount);
    }

    public static function lifecycleActions(): array { return [['clear', false], ['close', false], ['detach', false], ['clear', true], ['close', true], ['detach', true]]; }

    #[DataProvider('lifecycleActions')]
    public function testLifecycleExcludesOwners(string $action, bool $l2): void
    {
        if ($l2) { $this->enableL2(); }
        $groups = $this->loadGroups();
        if ($action === 'detach') {
            $this->em->detach($groups[0]); $this->reset(); $groups[1]->roles->toArray();
            self::assertFalse($groups[0]->roles->isInitialized());
            self::assertTrue($groups[3]->roles->isInitialized());
        } else {
            $this->em->$action(); $this->reset(); $groups[0]->roles->toArray();
            self::assertFalse($groups[1]->roles->isInitialized());
        }
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
    }

    public function testSqlErrorLeavesQueueAndPendingAddsRetryable(): void
    {
        $groups = $this->loadGroups();
        $new = new ManyBatchRole('new', 'new-role', 1);
        $groups[0]->roles->add($new);
        $this->em->getConnection()->executeStatement('ALTER TABLE modernorm_test_m2m_batch_roles RENAME COLUMN position TO temporary_position');
        try { $groups[0]->roles->toArray(); self::fail('Expected SQL error'); }
        catch (\Doctrine\DBAL\Exception) {
            self::assertFalse($groups[0]->roles->isInitialized());
            self::assertTrue($groups[0]->roles->unwrap()->contains($new));
        }
        $this->em->getConnection()->executeStatement('ALTER TABLE modernorm_test_m2m_batch_roles RENAME COLUMN temporary_position TO position');
        $this->reset();
        self::assertCount(4, $groups[0]->roles);
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
    }

    public function testEmptyBatchInitializesEveryCollection(): void
    {
        $groups = $this->em->getRepository(ManyBatchGroup::class)->findBy(['id' => [3, 5]], ['id' => 'ASC']);
        $this->reset();
        self::assertTrue($groups[0]->roles->isEmpty());
        self::assertTrue($groups[1]->roles->isInitialized());
        self::assertFalse($groups[1]->roles->isDirty());
        self::assertSame([], $groups[1]->roles->getSnapshot());
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
    }

    public function testAnotherManagerAndReplacedCollectionsAreExcluded(): void
    {
        $groups = $this->loadGroups();
        $old = $groups[0]->roles;
        $groups[0]->roles = new \Doctrine\Common\Collections\ArrayCollection();
        $other = new EntityManager($this->em->getConnection(), $this->em->getConfiguration());
        $otherGroups = $other->getRepository(ManyBatchGroup::class)->findBy([], ['id' => 'ASC']);
        $this->reset();
        $otherGroups[0]->roles->toArray();
        self::assertFalse($groups[1]->roles->isInitialized());
        $groups[1]->roles->toArray();
        self::assertFalse($old->isInitialized());
        self::assertTrue($groups[0]->roles->isEmpty());
        self::assertTrue($groups[3]->roles->isInitialized());
        self::assertSame(2, $this->statistics->snapshot()->sqlCount);
    }

    public function testL2EnabledManagerBatchesUncachedTargets(): void
    {
        $config = clone $this->em->getConfiguration();
        $config->setSecondLevelCache(new \Symfony\Component\Cache\Adapter\ArrayAdapter());
        $other = new EntityManager($this->em->getConnection(), $config);
        $groups = $other->getRepository(ManyBatchGroup::class)->findBy([], ['id' => 'ASC']);
        $this->reset();
        self::assertSame(['a', 'b', 'shared'], $groups[0]->roles->getKeys());
        self::assertTrue($groups[1]->roles->isInitialized());
        self::assertSame($groups[0]->roles->get('shared'), $groups[1]->roles->get('shared'));
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        self::assertStringContainsString(' IN (', $this->recorder->sql[0]);
        foreach ($groups as $group) { $group->roles->toArray(); }
        self::assertSame(3, $this->statistics->snapshot()->sqlCount);
    }

    public function testEagerModeKeepsItsOwnStrategy(): void
    {
        $query = $this->em->createQueryBuilder()->fromEntity(ManyBatchGroup::class, 'g')->orderBy('g.id')->getQuery();
        $query->setFetchMode(ManyBatchGroup::class, 'roles', ORM\ClassMetadata::FETCH_EAGER);
        $groups = $query->getResult();
        $this->reset();
        foreach ($groups as $group) {
            self::assertTrue($group->roles->isInitialized());
            $group->roles->toArray();
        }
        self::assertSame(0, $this->statistics->snapshot()->sqlCount);
    }

    public function testImmutableOwnerCanBatchAndPersistNewLinks(): void
    {
        $this->em->getClassMetadata(ManyBatchGroup::class)->markReadOnly();
        $groups = $this->loadGroups();
        $groups[0]->roles->add(new ManyBatchRole('new', 'new-role', 1));
        $this->reset();
        self::assertCount(4, $groups[0]->roles);
        self::assertTrue($groups[1]->roles->isInitialized());
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        $this->em->flush();
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_m2m_batch_links WHERE role_id = ?', ['new']));
    }

    public function testSelfReferencingMappingUsesTheCorrectJoinTableColumns(): void
    {
        for ($id = 1; $id <= 4; ++$id) { $this->em->getConnection()->insert('modernorm_test_m2m_batch_nodes', ['id' => $id, 'name' => 'node-' . $id]); }
        foreach ([[1, 2], [1, 3], [2, 3]] as [$source, $target]) {
            $this->em->getConnection()->insert('modernorm_test_m2m_batch_follows', ['source_id' => $source, 'target_id' => $target]);
        }
        $nodes = $this->em->getRepository(ManyBatchNode::class)->findBy([], ['id' => 'ASC']);
        $this->reset();
        self::assertSame([3, 2], $nodes[0]->follows->getKeys());
        self::assertSame([3], $nodes[1]->follows->getKeys());
        self::assertSame($nodes[0]->follows->get(3), $nodes[1]->follows->get(3));
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        self::assertSame(['node-1', 'node-2'], $nodes[2]->followedBy->getKeys());
        self::assertSame($nodes[0], $nodes[2]->followedBy->get('node-1'));
        self::assertTrue($nodes[0]->followedBy->isEmpty());
        self::assertSame(2, $this->statistics->snapshot()->sqlCount);
    }

    public function testPostLoadRunsOncePerSharedTarget(): void
    {
        $groups = $this->loadGroups();
        $listener = new class {
            public array $ids = [];
            public function postLoad(\ModernORM\Event\PostLoadEventArgs $event): void
            {
                if ($event->getObject() instanceof ManyBatchRole) { $this->ids[] = $event->getObject()->id; }
            }
        };
        $this->em->getEventManager()->addEventListener([\ModernORM\Events::postLoad], $listener);
        $this->reset();
        $groups[0]->roles->toArray();
        sort($listener->ids, SORT_STRING);
        self::assertSame(['01', '1', 'a b'], $listener->ids);
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
    }

    public function testPendingTargetDeleteDoesNotReappearInCollections(): void
    {
        $groups = $this->loadGroups();
        $role = $this->em->find(ManyBatchRole::class, 'a b');
        $this->em->remove($role);
        self::assertSame(['a', 'b'], $groups[0]->roles->getKeys());
        self::assertTrue($groups[1]->roles->isEmpty());
        $this->em->flush();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_m2m_batch_links WHERE role_id = ?', ['a b']));
    }
    private function enableL2(): void
    {
        $config = clone $this->em->getConfiguration();
        $config->setSecondLevelCache(new \Symfony\Component\Cache\Adapter\ArrayAdapter());
        $this->em = new EntityManager($this->em->getConnection(), $config);
    }
}
