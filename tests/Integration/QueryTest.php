<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\DriverManager;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\Tools\Pagination\Paginator;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: 'modernorm_test_projects')]
final class QueryProject
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column]
    public string $name;

    /** @var Collection<int, QueryTask> */
    #[ORM\OneToMany(mappedBy: 'project', targetEntity: QueryTask::class, cascade: ['persist'])]
    #[ORM\OrderBy(['title' => 'ASC'])]
    public Collection $tasks;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->tasks = new ArrayCollection();
    }
}

#[ORM\Entity, ORM\Table(name: 'modernorm_test_tasks')]
final class QueryTask
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column]
    public string $title;

    #[ORM\ManyToOne(inversedBy: 'tasks'), ORM\JoinColumn(nullable: false)]
    public QueryProject $project;

    public function __construct(QueryProject $project, string $title)
    {
        $this->project = $project;
        $this->title = $title;
    }
}

final class QueryTest extends TestCase
{
    private EntityManager $em;
    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432,
            'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres',
            'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ]);
        $this->em = new EntityManager($connection, ORMSetup::createAttributeMetadataConfig(
            [__DIR__], true, cache: new ArrayAdapter(),
        ));
        $metadata = array_map($this->em->getClassMetadata(...), [QueryProject::class, QueryTask::class]);
        $schema = new SchemaTool($this->em);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        foreach (['alpha' => ['a', 'b', 'c'], 'beta' => ['d'], 'empty' => []] as $name => $titles) {
            $project = new QueryProject($name);
            foreach ($titles as $title) {
                $project->tasks->add(new QueryTask($project, $title));
            }
            $this->em->persist($project);
            $this->em->flush();
            $this->ids[$name] = $project->id;
        }
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DROP TABLE IF EXISTS modernorm_test_tasks, modernorm_test_projects');
        $this->em->getConnection()->close();
    }

    private function graph(): \ModernORM\QueryBuilder
    {
        return $this->em->createQueryBuilder()->fromEntity(QueryProject::class, 'p')
            ->leftJoin('p', 'modernorm_test_tasks', 't', 't.project_id = p.id')
            ->addJoinedEntity(QueryTask::class, 't', 'p', 'tasks')
            ->orderBy('p.name')->addOrderBy('t.title');
    }

    public function testSqlNamedParameterAndIdentityMap(): void
    {
        $query = $this->em->createQueryBuilder()->fromEntity(QueryProject::class, 'p')
            ->where('p.name = :name')->setParameter('name', 'alpha')->getQuery();
        $project = $query->getSingleResult();
        self::assertSame('alpha', $project->name);
        self::assertSame($project, $this->em->find(QueryProject::class, $this->ids['alpha']));
        self::assertSame([], $query->setParameter('name', "alpha' OR 1=1 --")->getResult());
    }

    public function testQueryBuilderArrayParameterOrderingAndLimit(): void
    {
        $query = $this->em->createQueryBuilder()->fromEntity(QueryProject::class, 'p')
            ->where('p.id IN (:ids)')->setParameter('ids', array_values($this->ids), ArrayParameterType::INTEGER)
            ->orderBy('p.name', 'ASC')->setFirstResult(1)->setMaxResults(1)->getQuery();
        self::assertSame('beta', $query->getSingleResult()->name);
    }

    public function testFetchJoinDeduplicatesRootsAndInitializesEmptyCollections(): void
    {
        $projects = $this->graph()->getQuery()->getResult();
        self::assertCount(3, $projects);
        self::assertCount(3, $projects[0]->tasks);
        self::assertTrue($projects[0]->tasks->isInitialized());
        self::assertCount(0, $projects[2]->tasks);
        self::assertTrue($projects[2]->tasks->isInitialized());
        foreach ($projects[0]->tasks as $task) {
            self::assertSame($projects[0], $task->project);
            self::assertSame($task, $this->em->find(QueryTask::class, $task->id));
        }
    }

    public function testArrayHydrationProducesNestedValuesWithoutManagingEntities(): void
    {
        $rows = $this->graph()->getQuery()->getArrayResult();
        self::assertCount(3, $rows);
        self::assertSame('alpha', $rows[0]['name']);
        self::assertCount(3, $rows[0]['tasks']);
        self::assertSame([], $rows[2]['tasks']);
        self::assertSame([], $this->em->getUnitOfWork()->getIdentityMap());
    }

    public function testScalarAndMixedHydration(): void
    {
        $query = $this->em->createQueryBuilder()->from('modernorm_test_projects', 'p')
            ->leftJoin('p', 'modernorm_test_tasks', 't', 't.project_id = p.id')
            ->addScalar('p.name', 'name', 'name')->addScalar('COUNT(t.id)', 'total', 'total', 'integer')
            ->groupBy('p.id')->orderBy('p.name');
        $rows = $query->getQuery()->getScalarResult();
        self::assertSame(['alpha', 'beta', 'empty'], array_column($rows, 'name'));
        self::assertSame([3, 1, 0], array_column($rows, 'total'));
        self::assertSame(4, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_tasks'));
        $mixed = $this->em->createQueryBuilder()->fromEntity(QueryProject::class, 'p')
            ->leftJoin('p', 'modernorm_test_tasks', 't', 't.project_id = p.id')
            ->addScalar('COUNT(t.id)', 'total', 'total', 'integer')->groupBy('p.id')->orderBy('p.name')->getQuery()->getResult();
        self::assertInstanceOf(QueryProject::class, $mixed[0][0]);
        self::assertSame(3, $mixed[0]['total']);
        self::assertSame($mixed[0][0], $this->em->find(QueryProject::class, $this->ids['alpha']));
    }

    public function testPaginatorLimitsRootsWithCollectionFetchJoin(): void
    {
        $roots = $this->em->getConnection()->createQueryBuilder()->select('id')
            ->from('modernorm_test_projects')->orderBy('name')->setFirstResult(0)->setMaxResults(1);
        $query = $this->graph()->where('p.id IN (:__orm_page_ids)')->getQuery();
        $page = new Paginator($query, $roots);
        self::assertCount(3, $page);
        $projects = iterator_to_array($page);
        self::assertCount(1, $projects);
        self::assertSame('alpha', $projects[0]->name);
        self::assertCount(3, $projects[0]->tasks);
        self::assertTrue($projects[0]->tasks->isInitialized());
    }

    public function testIterableHydrationAndPositionalParameter(): void
    {
        $query = $this->em->createQueryBuilder()->fromEntity(QueryTask::class, 't')
            ->where('t.title >= ?')->orderBy('t.title')->setParameter(0, 'b')->getQuery();
        $titles = [];
        foreach ($query->toIterable() as $task) {
            $titles[] = $task->title;
            $this->em->detach($task);
        }
        self::assertSame(['b', 'c', 'd'], $titles);
    }

    public function testNativeSqlMapsEntitiesAndParameters(): void
    {
        $mapping = new \ModernORM\Query\ResultSetMappingBuilder($this->em);
        $mapping->addRootEntityFromClassMetadata(QueryProject::class, 'p');
        $query = $this->em->createNativeQuery(
            'SELECT ' . $mapping->generateSelectClause(['p' => 'p']) . ' FROM modernorm_test_projects p WHERE p.name = :name',
            $mapping,
        )->setParameter('name', 'alpha');
        $project = $query->getSingleResult();
        self::assertSame('alpha', $project->name);
        self::assertSame($project, $this->em->find(QueryProject::class, $this->ids['alpha']));
        $query->setParameter('name', "alpha' OR 1=1 --");
        self::assertSame([], $query->getResult());
    }

    public function testNativeSqlJoinMapsCollectionsAndEmptyResults(): void
    {
        $mapping = new \ModernORM\Query\ResultSetMappingBuilder(
            $this->em, \ModernORM\Query\ResultSetMappingBuilder::COLUMN_RENAMING_INCREMENT,
        );
        $mapping->addRootEntityFromClassMetadata(QueryProject::class, 'p');
        $mapping->addJoinedEntityFromClassMetadata(QueryTask::class, 't', 'p', 'tasks');
        $sql = 'SELECT ' . $mapping->generateSelectClause(['p' => 'p', 't' => 't'])
            . ' FROM modernorm_test_projects p LEFT JOIN modernorm_test_tasks t ON t.project_id = p.id ORDER BY p.name, t.title';
        $projects = $this->em->createNativeQuery($sql, $mapping)->getResult();
        self::assertCount(3, $projects);
        self::assertCount(3, $projects[0]->tasks);
        self::assertTrue($projects[0]->tasks->isInitialized());
        self::assertCount(0, $projects[2]->tasks);
        self::assertTrue($projects[2]->tasks->isInitialized());
        foreach ($projects[0]->tasks as $task) {
            self::assertSame($projects[0], $task->project);
        }
    }

    public function testNativeSqlScalarMappingUsesPostgresqlTypes(): void
    {
        $mapping = new \ModernORM\Query\ResultSetMapping();
        $mapping->addScalarResult('total', 'total', 'integer');
        $mapping->addScalarResult('matches', 'matches', 'boolean');
        $rows = $this->em->createNativeQuery(
            'SELECT COUNT(*)::int AS total, COUNT(*) = 4 AS matches FROM modernorm_test_tasks', $mapping,
        )->getScalarResult();
        self::assertSame([['total' => 4, 'matches' => true]], $rows);
        self::assertSame([], $this->em->getUnitOfWork()->getIdentityMap());
    }

    public function testBulkUpdateLeavesManagedStateStaleUntilRefresh(): void
    {
        $project = $this->em->find(QueryProject::class, $this->ids['alpha']);
        $affected = $this->em->createQueryBuilder()->update('modernorm_test_projects')
            ->set('name', ':name')->where('id = :id')
            ->setParameter('name', 'bulk')->setParameter('id', $project->id)->executeStatement();
        self::assertSame(1, $affected);
        self::assertSame('alpha', $project->name);
        self::assertSame('alpha', $this->em->getUnitOfWork()->getOriginalEntityData($project)['name']);
        self::assertSame($project, $this->em->find(QueryProject::class, $project->id));
        $this->em->flush();
        self::assertSame('bulk', $this->em->getConnection()->fetchOne('SELECT name FROM modernorm_test_projects WHERE id = ?', [$project->id]));
        $this->em->refresh($project);
        self::assertSame('bulk', $project->name);
        self::assertSame('bulk', $this->em->getUnitOfWork()->getOriginalEntityData($project)['name']);
        $project->name = 'tracked';
        $this->em->flush();
        $this->em->clear();
        self::assertSame('tracked', $this->em->find(QueryProject::class, $this->ids['alpha'])->name);
    }

    public function testNativeWriteAndNativeReadDoNotRefreshManagedEntityAutomatically(): void
    {
        $project = $this->em->find(QueryProject::class, $this->ids['alpha']);
        self::assertSame(1, $this->em->getConnection()->executeStatement(
            'UPDATE modernorm_test_projects SET name = ? WHERE id = ?', ['native', $project->id],
        ));
        $mapping = new \ModernORM\Query\ResultSetMappingBuilder($this->em);
        $mapping->addRootEntityFromClassMetadata(QueryProject::class, 'p');
        $loaded = $this->em->createNativeQuery('SELECT id, name FROM modernorm_test_projects WHERE id = :id', $mapping)
            ->setParameter('id', $project->id)->getSingleResult();
        self::assertSame($project, $loaded);
        self::assertSame('alpha', $loaded->name);
        $this->em->refresh($project);
        self::assertSame('native', $project->name);
    }

    public function testBulkUpdateDoesNotFlushPendingChangesAndClearDiscardsThem(): void
    {
        $project = $this->em->find(QueryProject::class, $this->ids['alpha']);
        $project->name = 'pending';
        $affected = $this->em->createQueryBuilder()->update('modernorm_test_projects')->set('name', ':new')->where('name = :old')
            ->setParameter('new', 'bulk')->setParameter('old', 'alpha')->executeStatement();
        self::assertSame(1, $affected);
        self::assertSame('pending', $project->name);
        self::assertSame('bulk', $this->em->getConnection()->fetchOne('SELECT name FROM modernorm_test_projects WHERE id = ?', [$project->id]));
        $this->em->clear();
        self::assertFalse($this->em->contains($project));
        $this->em->flush();
        $reloaded = $this->em->find(QueryProject::class, $project->id);
        self::assertNotSame($project, $reloaded);
        self::assertSame('bulk', $reloaded->name);
    }

    public function testBulkDeleteLeavesIdentityMapAndInitializedCollectionsStaleUntilClear(): void
    {
        $project = $this->em->find(QueryProject::class, $this->ids['alpha']);
        $tasks = $project->tasks->toArray();
        $taskId = $tasks[0]->id;
        $affected = $this->em->createQueryBuilder()->delete('modernorm_test_tasks')
            ->where('project_id = :project')->setParameter('project', $project->id)->executeStatement();
        self::assertSame(3, $affected);
        self::assertCount(3, $project->tasks);
        self::assertTrue($this->em->contains($tasks[0]));
        self::assertSame($tasks[0], $this->em->find(QueryTask::class, $taskId));
        self::assertSame([], $this->em->getUnitOfWork()->getScheduledEntityDeletions());
        $this->em->clear();
        self::assertNull($this->em->find(QueryTask::class, $taskId));
        self::assertCount(0, $this->em->find(QueryProject::class, $this->ids['alpha'])->tasks);
    }

    public function testBulkDmlRollsBackInsideExplicitTransaction(): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            self::assertSame(1, $this->em->createQueryBuilder()->update('modernorm_test_projects')->set('name', ':name')->where('id = :id')
                ->setParameter('name', 'rolled-back')->setParameter('id', $this->ids['alpha'])->executeStatement());
            self::assertSame(4, $this->em->createQueryBuilder()->delete('modernorm_test_tasks')->executeStatement());
        } finally {
            $connection->rollBack();
            $this->em->clear();
        }
        self::assertSame('alpha', $this->em->find(QueryProject::class, $this->ids['alpha'])->name);
        self::assertSame(4, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM modernorm_test_tasks'));
    }

    public function testBulkDmlBypassesEntityLifecycleEvents(): void
    {
        $listener = new class {
            public int $updates = 0;
            public int $removals = 0;
            public function postUpdate(): void { ++$this->updates; }
            public function postRemove(): void { ++$this->removals; }
        };
        $this->em->getEventManager()->addEventListener(['postUpdate', 'postRemove'], $listener);
        $this->em->createQueryBuilder()->update('modernorm_test_projects')->set('name', ':name')->where('id = :id')
            ->setParameter('name', 'bulk')->setParameter('id', $this->ids['alpha'])->executeStatement();
        $this->em->createQueryBuilder()->delete('modernorm_test_tasks')->where('title = :title')
            ->setParameter('title', 'd')->executeStatement();
        self::assertSame(0, $listener->updates);
        self::assertSame(0, $listener->removals);
        $project = $this->em->find(QueryProject::class, $this->ids['empty']);
        $project->name = 'tracked';
        $this->em->flush();
        self::assertSame(1, $listener->updates);
        $this->em->remove($project);
        $this->em->flush();
        self::assertSame(1, $listener->removals);
    }

    public function testSqlBuilderCloneAndQueryLimitsAreIndependent(): void
    {
        $builder = $this->em->createQueryBuilder()->fromEntity(QueryProject::class, 'p')->orderBy('p.name');
        $limited = clone $builder;
        $limited->setMaxResults(1);
        $query = $limited->getQuery();
        self::assertSame('alpha', $query->getSingleResult()->name);
        self::assertSame('beta', $query->setFirstResult(1)->getSingleResult()->name);
        self::assertCount(3, $builder->getQuery()->getResult());
    }

    public function testCteSqlRetainsEntityHydration(): void
    {
        $ids = $this->em->getConnection()->createQueryBuilder()->select('id')->from('modernorm_test_projects')
            ->where('name = :name');
        $query = $this->em->createQueryBuilder()->with('chosen', $ids)->fromEntity(QueryProject::class, 'p')
            ->innerJoin('p', 'chosen', 'c', 'c.id = p.id')->setParameter('name', 'alpha')->getQuery();
        self::assertStringStartsWith('WITH chosen AS', $query->getSQL());
        $entity = $query->getSingleResult();
        self::assertSame($this->em->find(QueryProject::class, $this->ids['alpha']), $entity);
    }

    public function testSqlEntityQueryPreservesManagedStateUntilExplicitRefresh(): void
    {
        $entity = $this->em->find(QueryProject::class, $this->ids['alpha']);
        $this->em->getConnection()->executeStatement('UPDATE modernorm_test_projects SET name = ? WHERE id = ?', ['external', $entity->id]);
        $query = $this->em->createQueryBuilder()->fromEntity(QueryProject::class, 'p')
            ->where('p.id = :id')->setParameter('id', $entity->id)->getQuery();
        self::assertSame($entity, $query->getSingleResult());
        self::assertSame('alpha', $entity->name);
        $query->setHint(\ModernORM\Query::HINT_REFRESH, true)->getSingleResult();
        self::assertSame('external', $entity->name);
        self::assertSame('external', $this->em->getUnitOfWork()->getOriginalEntityData($entity)['name']);
    }

    public function testStreamingCollectionFetchIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->graph()->getQuery()->toIterable();
    }

    public function testRowLimitOnCollectionFetchIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->graph()->setMaxResults(1)->getQuery()->getResult();
    }

    public function testReplacingEntityProjectionIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->em->createQueryBuilder()->fromEntity(QueryProject::class, 'p')->select('p.id');
    }

    public function testUnmappedOrmHydrationIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->em->createQueryBuilder()->select('id')->from('modernorm_test_projects')->getQuery();
    }

    public function testPaginatorOffsetAndEmptyPagePreserveCollections(): void
    {
        $roots = $this->em->getConnection()->createQueryBuilder()->select('id')->from('modernorm_test_projects')
            ->orderBy('name')->setFirstResult(1)->setMaxResults(2);
        $query = $this->graph()->where('p.id IN (:__orm_page_ids)')->getQuery();
        $page = new Paginator($query, $roots);
        self::assertCount(3, $page);
        $entities = iterator_to_array($page);
        self::assertSame(['beta', 'empty'], array_map(static fn (QueryProject $p): string => $p->name, $entities));
        self::assertCount(1, $entities[0]->tasks);
        self::assertCount(0, $entities[1]->tasks);
        self::assertTrue($entities[1]->tasks->isInitialized());
        $roots->setFirstResult(20);
        self::assertSame([], iterator_to_array(new Paginator($query, $roots)));
    }

    public function testPostgresqlBulkReturningDoesNotImplicitlyRefreshUnitOfWork(): void
    {
        $entity = $this->em->find(QueryProject::class, $this->ids['alpha']);
        $rows = $this->em->getConnection()->executeQuery(
            'UPDATE modernorm_test_projects SET name = :name WHERE id = :id RETURNING id, name',
            ['name' => 'returning', 'id' => $entity->id],
        )->fetchAllAssociative();
        self::assertSame([['id' => $entity->id, 'name' => 'returning']], $rows);
        self::assertSame('alpha', $entity->name);
        $this->em->refresh($entity);
        self::assertSame('returning', $entity->name);
    }

    public function testExplicitManyToOneJoinHydratesSharedParent(): void
    {
        $tasks = $this->em->createQueryBuilder()->fromEntity(QueryTask::class, 't')
            ->innerJoin('t', 'modernorm_test_projects', 'p', 'p.id = t.project_id')
            ->addJoinedEntity(QueryProject::class, 'p', 't', 'project')
            ->orderBy('t.title')->getQuery()->getResult();
        self::assertCount(4, $tasks);
        self::assertSame($tasks[0]->project, $tasks[1]->project);
        self::assertFalse($this->em->isUninitializedObject($tasks[0]->project));
        self::assertSame('alpha', $tasks[0]->project->name);
        self::assertSame($tasks[0]->project, $this->em->find(QueryProject::class, $this->ids['alpha']));
    }

    public function testRawSqlBuilderReturnsRowsWithoutManagingEntities(): void
    {
        $rows = $this->em->createQueryBuilder()->select('id', 'name')->from('modernorm_test_projects')
            ->where('name = :name')->setParameter('name', 'alpha')->executeQuery()->fetchAllAssociative();
        self::assertSame([['id' => $this->ids['alpha'], 'name' => 'alpha']], $rows);
        self::assertSame([], $this->em->getUnitOfWork()->getIdentityMap());
    }

    public function testClonedSqlQueryRetainsIndependentParametersAndHints(): void
    {
        $query = $this->em->createQueryBuilder()->fromEntity(QueryProject::class, 'p')
            ->where('p.name = :name')->setParameter('name', 'alpha', \Doctrine\DBAL\Types\Type::getType('string'))->getQuery();
        $query->setHint(\ModernORM\Query::HINT_READ_ONLY, true);
        $copy = clone $query;
        self::assertTrue($copy->getHint(\ModernORM\Query::HINT_READ_ONLY));
        self::assertSame('alpha', $copy->getSingleResult()->name);
        $copy->setParameter('name', 'beta');
        self::assertSame('beta', $copy->getSingleResult()->name);
        self::assertSame('alpha', $query->getSingleResult()->name);
    }

    public function testPaginatorRetainsBindingsOnBothQueries(): void
    {
        $roots = $this->em->getConnection()->createQueryBuilder()->select('id')->from('modernorm_test_projects')
            ->where('name LIKE :pattern')->setParameter('pattern', 'a%')->orderBy('name')->setMaxResults(1);
        $query = $this->graph()->where('p.name LIKE :pattern AND p.id IN (:__orm_page_ids)')
            ->setParameter('pattern', 'a%')->getQuery();
        $page = new Paginator($query, $roots);
        self::assertCount(1, $page);
        $entities = iterator_to_array($page);
        self::assertCount(1, $entities);
        self::assertSame('alpha', $entities[0]->name);
        self::assertCount(3, $entities[0]->tasks);
    }

}
