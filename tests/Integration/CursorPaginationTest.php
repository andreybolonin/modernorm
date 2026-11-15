<?php

declare(strict_types=1);

namespace ModernORM\Tests\Integration;

use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use ModernORM\Behavior\SoftDeleteTrait;
use ModernORM\EntityManager;
use ModernORM\Mapping as ORM;
use ModernORM\ORMSetup;
use ModernORM\QueryBuilder;
use ModernORM\Statistics;
use ModernORM\Tools\Pagination\Cursor;
use ModernORM\Tools\Pagination\CursorPaginator;
use ModernORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[ORM\Entity, ORM\Table(name: '`CursorRecords`')]
class CursorRecord
{
    #[ORM\Id, ORM\Column(type: 'bigint')]
    public int|string $id;
    #[ORM\Column(type: 'integer')]
    public int $tenant = 1;
    #[ORM\Column(name: '`OccurredAt`', type: 'datetime_immutable', columnDefinition: 'TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL')]
    public DateTimeImmutable $occurredAt;
    #[ORM\Column(type: 'integer')]
    public int $score = 0;
    #[ORM\Column(type: 'decimal', precision: 24, scale: 6)]
    public string $amount = '0';
    #[ORM\Column(type: 'boolean')]
    public bool $enabled = false;
    #[ORM\Column(type: 'json')]
    public array $payload = [];
    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $note = null;
    #[ORM\ManyToOne(targetEntity: self::class)]
    public ?self $parent = null;
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    public Collection $children;
}

#[ORM\Entity, ORM\Table(name: 'cursor_notes'), ORM\SoftDelete]
class CursorDeletedNote
{
    use SoftDeleteTrait;
    #[ORM\Id, ORM\Column(type: 'integer')]
    public int $id;
}

final class CursorPaginationTest extends TestCase
{
    private EntityManager $em;
    private Statistics $statistics;
    private string $schema;
    private string $table;

    protected function setUp(): void
    {
        $this->schema = 'modernorm_cursor_' . bin2hex(random_bytes(6));
        $this->table = '"' . $this->schema . '"."CursorRecords"';
        $this->statistics = new Statistics(enabled: true);
        $dbal = new DbalConfiguration();
        $dbal->setSchemaAssetsFilter(static fn (): bool => false);
        $dbal->setMiddlewares([new \ModernORM\Statistics\Middleware($this->statistics)]);
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: 5432, 'user' => getenv('PGUSER') ?: 'postgres',
            'password' => getenv('PGPASSWORD') ?: 'postgres', 'dbname' => getenv('PGDATABASE') ?: 'modernorm_test',
        ], $dbal);
        $this->em = new EntityManager($connection, ORMSetup::createAttributeMetadataConfig([], true, cache: new ArrayAdapter()));
        $classes = [$this->em->getClassMetadata(CursorRecord::class), $this->em->getClassMetadata(CursorDeletedNote::class)];
        foreach ($classes as $metadata) {
            $metadata->table['schema'] = $this->schema;
        }
        (new SchemaTool($this->em))->createSchema($classes);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $connection->executeStatement('DROP SCHEMA IF EXISTS "' . $this->schema . '" CASCADE');
        $connection->close();
    }

    private function seed(): void
    {
        for ($id = 1; $id <= 8; ++$id) {
            $this->em->getConnection()->executeStatement(
                'INSERT INTO ' . $this->table . ' (id, tenant, "OccurredAt", score, amount, enabled, payload) VALUES (?, ?, ?, ?, ?, ?, \'{}\')',
                [$id, $id === 6 ? 2 : 1, '2026-01-01 00:00:00.' . sprintf('%06d', intdiv($id + 1, 2)), intdiv($id, 3),
                    '9007199254740993.' . sprintf('%06d', $id), $id % 2 === 0 ? 'true' : 'false'],
            );
        }
    }

    private function source(): QueryBuilder
    {
        return $this->em->createQueryBuilder()->fromEntity(CursorRecord::class, 'r')
            ->where('r.tenant = :tenant')->setParameter('tenant', 1);
    }

    private function ids(array $items): array
    {
        return array_map(static fn (object $item) => $item->id, $items);
    }

    public static function orders(): iterable
    {
        yield 'ascending compound' => [['occurredAt' => 'ASC', 'id' => 'ASC'], [1, 2, 3, 4, 5, 7, 8]];
        yield 'descending compound' => [['occurredAt' => 'DESC', 'id' => 'DESC'], [8, 7, 5, 4, 3, 2, 1]];
        yield 'mixed directions' => [['score' => 'ASC', 'id' => 'DESC'], [2, 1, 5, 4, 3, 8, 7]];
        yield 'single field' => [['id' => 'ASC'], [1, 2, 3, 4, 5, 7, 8]];
        yield 'decimal precision' => [['amount' => 'ASC', 'id' => 'ASC'], [1, 2, 3, 4, 5, 7, 8]];
        yield 'boolean' => [['enabled' => 'ASC', 'id' => 'ASC'], [1, 3, 5, 7, 2, 4, 8]];
    }

    #[DataProvider('orders')]
    public function testForwardAndBackwardNavigationWithoutGaps(array $order, array $expected): void
    {
        $this->seed();
        $paginator = new CursorPaginator(2, $order);
        $source = $this->source();
        $position = null;
        $seen = [];
        $pages = [];
        do {
            $page = $paginator->paginate($source, $position);
            $pages[] = $this->ids($page->getItems());
            $seen = [...$seen, ...$this->ids(iterator_to_array($page))];
            $position = $page->hasNextPage() ? $page->getNextCursorAsString() : null;
            self::assertCount(min(2, count($expected) - count($seen) + count($page)), $page);
        } while ($position !== null);
        self::assertSame($expected, $seen);
        for ($index = count($pages) - 2; $index >= 0; --$index) {
            self::assertTrue($page->hasPreviousPage());
            $page = $paginator->paginate($source, $page->getPreviousCursorAsString());
            self::assertSame($pages[$index], $this->ids($page->getItems()));
        }
        self::assertFalse($page->hasPreviousPage());
        self::assertSame(7, $page->getTotalCount());
    }

    public function testCountIsLazyAndLookaheadDoesNotHydrateExtraEntity(): void
    {
        $this->seed();
        $this->statistics->reset();
        $page = (new CursorPaginator(2, ['id' => 'ASC']))->paginate($this->source());
        self::assertSame(2, $this->statistics->snapshot()->sqlCount);
        self::assertSame(2, $this->em->getUnitOfWork()->size());
        self::assertSame([1, 2], $this->ids($page->getItems()));
        self::assertSame(7, $page->getTotalCount());
        self::assertSame(7, $page->getTotalCount());
        self::assertSame(3, $this->statistics->snapshot()->sqlCount);
    }

    public function testEmptyAndOutOfRangePagesDoNotRunAHydrationQuery(): void
    {
        $paginator = new CursorPaginator(2, ['id' => 'ASC']);
        $this->statistics->reset();
        $empty = $paginator->paginate($this->source());
        self::assertCount(0, $empty);
        self::assertFalse($empty->hasNextPage());
        self::assertFalse($empty->hasPreviousPage());
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        $this->seed();
        $this->statistics->reset();
        $outside = $paginator->paginate($this->source(), new Cursor(['id' => 999]));
        self::assertCount(0, $outside);
        self::assertFalse($outside->hasNextPage());
        self::assertFalse($outside->hasPreviousPage());
        self::assertSame(1, $this->statistics->snapshot()->sqlCount);
        self::assertSame(7, $outside->getTotalCount());
    }

    public function testReservedAndUnusedNamedBindingsArePreservedWithoutCollisions(): void
    {
        $this->seed();
        $source = $this->source()->where('r.tenant = :__orm_cursor_1')->setParameter('__orm_cursor_1', 1)
            ->orderBy(':only_order')->setParameter('only_order', 5);
        $paginator = new CursorPaginator(2, ['id' => 'ASC']);
        $page = $paginator->paginate($source, new Cursor(['id' => 2]));
        self::assertSame([3, 4], $this->ids($page->getItems()));
        self::assertSame(7, $page->getTotalCount());
    }

    public function testCursorSnapshotsPreserveMicrosecondsAndIgnoreDirtyManagedValues(): void
    {
        $this->seed();
        $entity = $this->em->find(CursorRecord::class, 2);
        $entity->occurredAt = new DateTimeImmutable('2099-01-01');
        $source = $this->source();
        $paginator = new CursorPaginator(2, ['occurredAt' => 'ASC', 'id' => 'ASC']);
        $page = $paginator->paginate($source);
        self::assertSame($entity, $page->getItems()[1]);
        self::assertSame('2026-01-01 00:00:00.000001', $page->getNextCursor()->getParameters()['occurredAt']);
        $before = $page->getNextCursorAsString();
        $entity->occurredAt = new DateTimeImmutable('2100-01-01');
        $this->em->clear();
        self::assertSame($before, $page->getNextCursorAsString());
        self::assertSame([3, 4], $this->ids($paginator->paginate($source, $before)->getItems()));
    }

    public function testInsertsAndDeletesBeforeTheBoundaryDoNotShiftTheNextPage(): void
    {
        $this->seed();
        $paginator = new CursorPaginator(2, ['id' => 'ASC']);
        $page = $paginator->paginate($this->source());
        $this->em->getConnection()->executeStatement('DELETE FROM ' . $this->table . ' WHERE id = 1');
        self::assertSame([3, 4], $this->ids($paginator->paginate($this->source(), $page->getNextCursorAsString())->getItems()));
        $this->em->getConnection()->executeStatement('INSERT INTO ' . $this->table . ' SELECT 0, tenant, "OccurredAt", score, amount, enabled, payload, note, parent_id FROM ' . $this->table . ' WHERE id = 2');
        self::assertSame([3, 4], $this->ids($paginator->paginate($this->source(), $page->getNextCursor())->getItems()));
    }

    public function testQueryAndPageSnapshotsCanBeReusedIndependently(): void
    {
        $this->seed();
        $source = $this->source()->orderBy('r.id', 'DESC');
        $sql = $source->getSQL();
        $paginator = new CursorPaginator(2, ['id' => 'ASC']);
        $first = $paginator->paginate($source);
        $source->setParameter('tenant', 2);
        $other = $paginator->paginate($source);
        self::assertSame([6], $this->ids($other->getItems()));
        self::assertSame([1, 2], $this->ids($first->getItems()));
        self::assertSame(7, $first->getTotalCount());
        self::assertSame($sql, $source->getSQL());
        self::assertSame(0, $source->getFirstResult());
        self::assertNull($source->getMaxResults());
        self::assertSame([3, 4], $this->ids($paginator->paginate($this->source(), $first->getNextCursor())->getItems()));
    }

    public function testDuplicateRootRowsAreDeduplicatedBeforeTheLimit(): void
    {
        $this->seed();
        $source = $this->source()->innerJoin('r', '(VALUES (1), (2), (3))', 'duplicates(n)', 'TRUE');
        $paginator = new CursorPaginator(2, ['id' => 'ASC']);
        $page = $paginator->paginate($source);
        self::assertSame([1, 2], $this->ids($page->getItems()));
        self::assertSame([3, 4], $this->ids($paginator->paginate($source, $page->getNextCursor())->getItems()));
        self::assertSame(7, $page->getTotalCount());
    }

    public function testToOneFetchJoinsRetainTheMappedAssociation(): void
    {
        $this->seed();
        $this->em->getConnection()->executeStatement('UPDATE ' . $this->table . ' SET parent_id = 1 WHERE id = 3');
        $source = $this->source()->where('r.id = 3')->leftJoin('r', $this->table, 'p', 'p.id = r.parent_id')
            ->addJoinedEntity(CursorRecord::class, 'p', 'r', 'parent');
        $page = (new CursorPaginator(2, ['id' => 'ASC']))->paginate($source);
        self::assertSame([3], $this->ids($page->getItems()));
        self::assertSame(1, $page->getItems()[0]->parent->id);
    }

    public function testBigIntegerIdsSurviveCursorEncoding(): void
    {
        $this->seed();
        $this->em->getConnection()->executeStatement('UPDATE ' . $this->table . ' SET id = ? WHERE id = 8', ['9223372036854775806']);
        $paginator = new CursorPaginator(1, ['id' => 'DESC']);
        $first = $paginator->paginate($this->source());
        self::assertSame('9223372036854775806', (string) $first->getNextCursor()->getParameters()['id']);
        self::assertSame([7], $this->ids($paginator->paginate($this->source(), $first->getNextCursorAsString())->getItems()));
    }

    public static function indexOrders(): iterable
    {
        yield 'uniform' => [['occurredAt' => 'ASC', 'id' => 'ASC'], 50000, '2026-01-01 00:00:00.016666'];
        yield 'mixed' => [['occurredAt' => 'DESC', 'id' => 'ASC'], 10000, '2026-01-01 00:00:00.003333'];
    }

    #[DataProvider('indexOrders')]
    public function testDeepPagesUseAnIndexRangeInsteadOfScanningThePrefix(array $order, int $id, string $time): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('INSERT INTO ' . $this->table . ' (id, tenant, "OccurredAt", score, amount, enabled, payload)'
            . " SELECT n, 1, TIMESTAMP '2026-01-01' + (n / 3) * INTERVAL '1 microsecond', 0, 0, false, '{}'::json FROM generate_series(1, 60000) n");
        $connection->executeStatement('CREATE INDEX cursor_seek ON ' . $this->table . ' (tenant, "OccurredAt" ' . $order['occurredAt'] . ', id ASC)');
        $connection->executeStatement('ANALYZE ' . $this->table);
        $cursor = new Cursor(['occurredAt' => $time, 'id' => $id]);
        $query = $this->source()->getCursorPaginationSource($order)->keyQuery($cursor)->setMaxResults(21);
        $plan = json_decode($connection->fetchOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) ' . $query->getSQL(), $query->getParameters(), $query->getParameterTypes()), true, flags: JSON_THROW_ON_ERROR);
        $nodes = $this->planNodes($plan[0]['Plan']);
        $scans = array_values(array_filter($nodes, static fn (array $node) => ($node['Relation Name'] ?? null) === 'CursorRecords'));
        self::assertNotEmpty($scans);
        foreach ($scans as $scan) {
            self::assertContains($scan['Node Type'], ['Index Only Scan', 'Index Scan']);
            self::assertStringContainsString('OccurredAt', $scan['Index Cond']);
            self::assertLessThanOrEqual(64, $scan['Actual Rows']);
            self::assertLessThanOrEqual(64, $scan['Rows Removed by Filter'] ?? 0);
        }
        self::assertEquals(21, $plan[0]['Plan']['Actual Rows']);
        $report = getenv('MODERNORM_CURSOR_EVIDENCE');
        if (is_string($report) && $report !== '') {
            file_put_contents($report . ($order['occurredAt'] === 'ASC' ? '-uniform.json' : '-mixed.json'), json_encode([
                'postgresql' => $connection->getServerVersion(), 'rows' => 60000, 'order' => $order,
                'cursor' => $cursor->toArray(),
                'sql' => str_replace($this->schema, 'cursor_schema', $query->getSQL()),
                'plan' => $plan,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }
    }

    private function planNodes(array $node): array
    {
        $nodes = [$node];
        foreach ($node['Plans'] ?? [] as $child) {
            $nodes = [...$nodes, ...$this->planNodes($child)];
        }
        return $nodes;
    }

    public function testSoftDeleteAndTenantFiltersRemainInForceForTamperedPositions(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('INSERT INTO "' . $this->schema . '".cursor_notes (id, deleted_at) VALUES (1, NULL), (2, NOW()), (3, NULL)');
        $paginator = new CursorPaginator(1, ['id' => 'ASC']);
        $source = $this->em->createQueryBuilder()->fromEntity(CursorDeletedNote::class, 'n');
        $first = $paginator->paginate($source);
        self::assertSame([1], $this->ids($first->getItems()));
        self::assertSame([3], $this->ids($paginator->paginate($source, $first->getNextCursor())->getItems()));
        self::assertSame(2, $first->getTotalCount());
        $this->seed();
        self::assertSame([7], $this->ids($paginator->paginate($this->source(), new Cursor(['id' => 6]))->getItems()));
    }
}
